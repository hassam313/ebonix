<?php
/*
 * File: king-include/king-pages/aitwin.php
 *
 * AI Twin page — upload a selfie, pick a vibe, generate a styled version of yourself.
 * Uses Fal AI Nano Banana 2 via twingenerate.php AJAX handler → Python gateway.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../');
    exit;
}

require_once QA_INCLUDE_DIR . 'king-app/format.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';

$userid = qa_get_logged_in_userid();

// ── Permission check ──────────────────────────────────────────────────────────
$permiterror = qa_user_maximum_permit_error('permit_post_q', QA_LIMIT_QUESTIONS);

if ($permiterror) {
    $qa_content = qa_content_prepare();
    switch ($permiterror) {
        case 'login':
            $econtent = qa_insert_login_links(qa_lang_html('question/ask_must_login'), qa_request());
            break;
        case 'confirm':
            $econtent = qa_insert_login_links(qa_lang_html('question/ask_must_confirm'), qa_request());
            break;
        case 'membership':
            $econtent = qa_insert_login_links(qa_lang_html('misc/mem_message'));
            $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-fingerprint fa-4x"></i><p>' . $econtent . '</p>'
                . '<a href="' . qa_path_html('membership') . '" class="meme-button">' . qa_lang_html('misc/see_plans') . '</a></div>';
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

// ── Membership credit limit check ──────────────────────────────────────────────
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

// ── Prepare page content ──────────────────────────────────────────────────────
$qa_content = qa_content_prepare();
$qa_content['title'] = 'AI Twin';

// Prevent Dropzone auto-init
$qa_content['head_lines'][] = '<script>if(typeof Dropzone!=="undefined"){Dropzone.autoDiscover=false;}</script>';

$king_ajax_url = rtrim((string)qa_opt('site_url'), '/') . '/king-include/king-ajax.php';

// ── Build page HTML ───────────────────────────────────────────────────────────
$cont = '';

// Nav bar (same pattern as submitai/videoai)
if (qa_opt('king_leo_enable') && qa_opt('enable_aivideo')) {
    $cont .= '<ul class="king-nav-kingsub-list" id="nav-kingsub">';
    if (qa_opt('king_leo_enable')) {
        $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('submitai') . '"><i class="fa-regular fa-image"></i> ' . qa_lang_html('misc/king_ai') . '</a></li>';
    }
    if (qa_opt('enable_aivideo')) {
        $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('videoai') . '"><i class="fa-regular fa-circle-play"></i> ' . qa_lang_html('misc/king_aivid') . '</a></li>';
    }
    $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('aitwin') . '" class="king-nav-kingsub-selected"><i class="fa-regular fa-user-circle"></i> AI Twin</a></li>';
    if (qa_opt('enable_aichat')) {
        $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('aichat') . '"><i class="fa-regular fa-comment-dots"></i> AI Chat</a></li>';
    }
    $cont .= '</ul>';
}
 
// ── Page header ───────────────────────────────────────────────────────────────
$cont .= '<div class="twin-page-header">';
$cont .= '<h1><i class="fa-regular fa-user-circle"></i> AI Twin</h1>';
$cont .= '<p>Create a personalized digital version of yourself in just a few steps.</p>';
$cont .= '<p class="twin-step-helper" style="margin:0;">Upload one clear selfie, choose your vibe, and generate your twin.</p>';
$cont .= '</div>';

// ── Card 1: Create Your Twin ──────────────────────────────────────────────────
$cont .= '<div class="twin-card" id="twin-create-card">';
$cont .= '<div class="twin-card-header"><i class="fa-solid fa-wand-magic-sparkles"></i> Create Your Twin</div>';

// Inline error area
$cont .= '<div class="twin-error" id="twin-error"></div>';

// Step 1 — Upload
$cont .= '<p class="twin-step-label"><span class="twin-step-num">1</span> Upload Your Photo</p>';
$cont .= '<p class="twin-step-helper">Best results: front-facing photo, good lighting, minimal blur</p>';
$cont .= '<div class="twin-upload-area" id="twin-upload-area">';
$cont .= '<input type="file" id="twin-file-input" accept="image/jpeg,image/png,image/webp">';
$cont .= '<i class="fa-solid fa-cloud-arrow-up"></i>';
$cont .= '<p><strong>Click to upload</strong> or drag &amp; drop your photo here</p>';
$cont .= '</div>';
$cont .= '<div id="twin-upload-chip-wrap" style="display:none;">';
$cont .= '<div class="twin-upload-chip">';
$cont .= '<img id="twin-thumb-preview" src="" alt="preview">';
$cont .= '<span class="chip-name" id="twin-chip-filename"></span>';
$cont .= '<button type="button" class="chip-remove" id="twin-remove-btn" title="Remove photo"><i class="fa-solid fa-xmark"></i></button>';
$cont .= '</div>';
$cont .= '</div>';

// Step 2 — Choose Vibe
$cont .= '<p class="twin-step-label" style="margin-top:22px;"><span class="twin-step-num">2</span> Choose Your Twin Vibe</p>';
$cont .= '<p class="twin-step-helper">Pick the look and energy you want your twin to have</p>';
$cont .= '<div class="twin-vibe-grid">';
$vibes = [
    'everyday'      => 'Everyday',
    'soft-glam'     => 'Soft Glam',
    'luxury'        => 'Luxury',
    'editorial'     => 'Editorial',
    'fantasy'       => 'Fantasy',
    'afro-futurist' => 'Afro Futurist',
];
foreach ($vibes as $vibe_key => $vibe_label) {
    $cont .= '<div class="twin-vibe-chip" data-vibe="' . qa_html($vibe_key) . '">' . qa_html($vibe_label) . '</div>';
}
$cont .= '</div>';

// Step 3 — Choose Format
$cont .= '<p class="twin-step-label" style="margin-top:22px;"><span class="twin-step-num">3</span> Choose Format</p>';
$cont .= '<div class="twin-format-row">';
$cont .= '<div class="twin-format-chip" data-ratio="1:1">Square (1:1)</div>';
$cont .= '<div class="twin-format-chip active" data-ratio="4:5">Portrait (4:5)</div>';
$cont .= '<span class="twin-more-sizes-link" id="twin-more-sizes-toggle">More sizes</span>';
$cont .= '</div>';
$cont .= '<div id="twin-extra-sizes" class="twin-format-row" style="margin-top:6px;">';
$cont .= '<div class="twin-format-chip" data-ratio="16:9">Widescreen (16:9)</div>';
$cont .= '<div class="twin-format-chip" data-ratio="5:4">Landscape (5:4)</div>';
$cont .= '<div class="twin-format-chip" data-ratio="2:3">Vertical (2:3)</div>';
$cont .= '</div>';

// Step 4 — Add Details
$cont .= '<p class="twin-step-label" style="margin-top:22px;"><span class="twin-step-num">4</span> Add Details <span style="font-weight:400;opacity:.55;font-size:.85em;">(optional)</span></p>';
$cont .= '<p class="twin-step-helper">Optional: describe hair, makeup, outfit, lighting, or mood</p>';
$cont .= '<input type="text" id="twin-details-input" class="twin-details-input"'
    . ' placeholder="Example: glowing skin, natural curls, luxury editorial feel" maxlength="300">';

// Step 5 — CTA
$cont .= '<button type="button" id="twin-cta-btn" class="twin-cta-btn" onclick="twinGenerate(this)">';
$cont .= '<span class="btn-text"><i class="fa-solid fa-wand-magic-sparkles"></i> Create My AI Twin</span>';
$cont .= '<span class="loader"></span>';
$cont .= '</button>';
$cont .= '<p class="twin-cta-subtext">Your twin will keep your features while transforming the style and mood.</p>';

$cont .= '</div>'; // #twin-create-card

// ── Ensure king_twins table exists before any SELECT ─────────────────────────
try {
    qa_db_query_sub(
        'CREATE TABLE IF NOT EXISTS `^king_twins` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `image_url` text NOT NULL,
          `thumbnail_url` text DEFAULT NULL,
          `vibe` varchar(64) NOT NULL,
          `format` varchar(16) NOT NULL DEFAULT \'4:5\',
          `details` text,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    qa_db_query_sub('ALTER TABLE ^king_twins ADD COLUMN IF NOT EXISTS thumbnail_url TEXT DEFAULT NULL');
} catch (Exception $e) {
    error_log('aitwin: table create error: ' . $e->getMessage());
}

// ── Card 2: Recent Twins ──────────────────────────────────────────────────────
$cont .= '<div class="twin-card" id="twin-recent-card">';
$cont .= '<div class="twin-card-header"><i class="fa-solid fa-clock-rotate-left"></i> Recent Twins</div>';

// Query king_twins table for this user's past results
$recent_twins = [];
if ($userid) {
    try {
        $recent_twins = qa_db_read_all_assoc(
            qa_db_query_sub(
                'SELECT id, image_url, vibe, format, created_at FROM ^king_twins WHERE user_id=# ORDER BY created_at DESC LIMIT 12',
                (int)$userid
            )
        );
    } catch (Exception $e) {
        // Table may not exist yet — silently continue
        $recent_twins = [];
    }
}

if (!empty($recent_twins)) {
    $cont .= '<div class="twin-results-grid">';
    foreach ($recent_twins as $twin) {
        $img_url = qa_html($twin['image_url']);
        $vibe    = qa_html(ucwords(str_replace('-', ' ', $twin['vibe'])));
        $date    = date('M j', strtotime($twin['created_at']));
        $cont .= '<div class="twin-result-thumb">';
        $cont .= '<img src="' . $img_url . '" alt="AI Twin" loading="lazy">';
        $cont .= '<div class="twin-result-thumb-meta">' . $vibe . ' · ' . $date . '</div>';
        $cont .= '</div>';
    }
    $cont .= '</div>';
} else {
    $cont .= '<div class="twin-empty-state">';
    $cont .= '<i class="fa-regular fa-user-circle"></i>';
    $cont .= '<p>Bring your twin to life — Upload a clear selfie to create a personalized AI version of yourself.</p>';
    $cont .= '</div>';
}

$cont .= '</div>'; // #twin-recent-card

// ── Card 3: Saved Twin Profiles (placeholder) ─────────────────────────────────
$cont .= '<div class="twin-placeholder-card">';
$cont .= '<h3><i class="fa-regular fa-bookmark"></i> Saved Twin Profiles</h3>';
$cont .= '<p>Coming soon — save your twin and generate new looks from it.</p>';
$cont .= '</div>';

// ── Inline JS ─────────────────────────────────────────────────────────────────
$cont .= '<script>';
$cont .= 'var TWIN_AJAX_URL = ' . json_encode($king_ajax_url) . ';';
$cont .= 'var TWIN_QA_ROOT  = ' . json_encode(rtrim((string)qa_opt('site_url'), '/') . '/') . ';';
$cont .= <<<'JSBLOCK'

// ── State ─────────────────────────────────────────────────────────────────────
var twinRefImageB64  = '';
var twinRefFilename  = '';
var twinVibe         = '';
var twinFormat       = '4:5';
var twinDetails      = '';
var twinLastPayload  = {};
var twinLastImageUrl = '';
var twinLastVibe     = '';
var twinLastFormat   = '';

// ── Upload area wiring ────────────────────────────────────────────────────────
(function () {
    var area    = document.getElementById('twin-upload-area');
    var fileInp = document.getElementById('twin-file-input');
    var chipWrap = document.getElementById('twin-upload-chip-wrap');
    var thumb    = document.getElementById('twin-thumb-preview');
    var fname    = document.getElementById('twin-chip-filename');
    var removeBtn = document.getElementById('twin-remove-btn');

    if (!area || !fileInp) return;

    area.addEventListener('click', function () { fileInp.click(); });

    area.addEventListener('dragover', function (e) {
        e.preventDefault();
        area.classList.add('drag-over');
    });
    area.addEventListener('dragleave', function () { area.classList.remove('drag-over'); });
    area.addEventListener('drop', function (e) {
        e.preventDefault();
        area.classList.remove('drag-over');
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files[0]) readTwinFile(files[0]);
    });

    fileInp.addEventListener('change', function () {
        if (fileInp.files && fileInp.files[0]) readTwinFile(fileInp.files[0]);
    });

    if (removeBtn) {
        removeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            twinRefImageB64 = '';
            twinRefFilename = '';
            fileInp.value = '';
            if (chipWrap) chipWrap.style.display = 'none';
            if (area)     area.style.display     = 'block';
        });
    }

    function readTwinFile(file) {
        if (!file.type.match(/^image\//)) {
            twinShowError('Please upload an image file (JPG, PNG, or WebP).');
            return;
        }
        if (file.size > 20 * 1024 * 1024) {
            twinShowError('File is too large. Please use an image under 20 MB.');
            return;
        }
        var reader = new FileReader();
        reader.onerror = function () { twinShowError('Could not read the file. Please try again.'); };
        reader.onload = function (ev) {
            var img = new Image();
            img.onerror = function () { twinShowError('Could not process the image. Please try another file.'); };
            img.onload = function () {
                var MAX = 1024;
                var w = img.width, h = img.height;
                if (w > h) { if (w > MAX) { h = Math.round(h * MAX / w); w = MAX; } }
                else        { if (h > MAX) { w = Math.round(w * MAX / h); h = MAX; } }
                var canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                twinRefImageB64 = canvas.toDataURL('image/jpeg', 0.85);
                twinRefFilename = file.name;
                if (thumb) thumb.src = twinRefImageB64;
                if (fname) fname.textContent = file.name;
                if (chipWrap) chipWrap.style.display = 'block';
                if (area)     area.style.display     = 'none';
                twinHideError();
            };
            img.src = ev.target.result;
        };
        reader.readAsDataURL(file);
    }
})();

// ── Vibe chips ────────────────────────────────────────────────────────────────
(function () {
    var chips = document.querySelectorAll('.twin-vibe-chip');
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            chips.forEach(function (c) { c.classList.remove('active'); });
            chip.classList.add('active');
            twinVibe = chip.getAttribute('data-vibe') || '';
            twinHideError();
        });
    });
})();

// ── Format chips ──────────────────────────────────────────────────────────────
(function () {
    function bindFormatChips() {
        var chips = document.querySelectorAll('.twin-format-chip');
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                document.querySelectorAll('.twin-format-chip').forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');
                twinFormat = chip.getAttribute('data-ratio') || '4:5';
            });
        });
    }
    bindFormatChips();

    var moreLink = document.getElementById('twin-more-sizes-toggle');
    var extraBox = document.getElementById('twin-extra-sizes');
    if (moreLink && extraBox) {
        var open = false;
        moreLink.addEventListener('click', function () {
            open = !open;
            extraBox.style.display = open ? 'flex' : 'none';
            moreLink.textContent = open ? 'Fewer sizes' : 'More sizes';
            if (open) bindFormatChips();
        });
    }
})();

// ── Details input ─────────────────────────────────────────────────────────────
(function () {
    var inp = document.getElementById('twin-details-input');
    if (inp) {
        inp.addEventListener('input', function () { twinDetails = inp.value; });
    }
})();

// ── Error helpers ─────────────────────────────────────────────────────────────
function twinShowError(msg) {
    var el = document.getElementById('twin-error');
    if (el) { el.textContent = msg; el.style.display = 'block'; }
}
function twinHideError() {
    var el = document.getElementById('twin-error');
    if (el) el.style.display = 'none';
}

// ── Generate ──────────────────────────────────────────────────────────────────
function twinGenerate(btn) {
    twinHideError();

    if (!twinRefImageB64) {
        twinShowError('Please upload a photo first (Step 1).');
        return false;
    }
    if (!twinVibe) {
        twinShowError('Please choose a Twin vibe (Step 2).');
        return false;
    }

    var detailsVal = (document.getElementById('twin-details-input') || {}).value || '';
    twinDetails = detailsVal;

    // Store payload for "Make More Like This"
    twinLastPayload = {
        ref_image_b64: twinRefImageB64,
        twin_vibe:     twinVibe,
        aspect_ratio:  twinFormat,
        twin_details:  twinDetails
    };
    twinLastVibe   = twinVibe;
    twinLastFormat = twinFormat;

    var ctaBtn = document.getElementById('twin-cta-btn');
    if (ctaBtn) { ctaBtn.classList.add('loading'); ctaBtn.disabled = true; }

    var fd = new FormData();
    fd.append('qa_operation',  'twingenerate');
    fd.append('qa_request',    'aitwin');
    fd.append('qa_root',       TWIN_QA_ROOT);
    fd.append('twin_vibe',     twinVibe);
    fd.append('twin_details',  twinDetails);
    fd.append('aspect_ratio',  twinFormat);
    fd.append('ref_image_b64', twinRefImageB64);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', TWIN_AJAX_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 120000;

    xhr.onload = function () {
        if (ctaBtn) { ctaBtn.classList.remove('loading'); ctaBtn.disabled = false; }
        if (xhr.status < 200 || xhr.status >= 300) {
            twinShowError('Server error (HTTP ' + xhr.status + '). Please try again.');
            return;
        }
        var raw = xhr.responseText || '';
        var data;
        try { data = JSON.parse(raw.trim()); } catch (e) {
            twinShowError('Unexpected response from server. Please try again.');
            return;
        }
        if (data.status === 'success' && data.image_url) {
            twinLastImageUrl = data.image_url;
            twinShowResult(data.image_url, data.vibe || twinVibe, data.format || twinFormat, twinDetails);
            // Update navbar coin display
            if (typeof ebonixRefreshCoinDisplay === 'function' && typeof data.coins_remaining !== 'undefined') {
                ebonixRefreshCoinDisplay(data.coins_remaining);
            }
        } else {
            var twinErr = data.message || 'Generation failed. Please try again.';
            if (typeof ebonixHandleCoinError === 'function' && ebonixHandleCoinError(twinErr, data)) {
                // Modal shown by coin handler
            } else {
                twinShowError(twinErr);
            }
        }
    };

    xhr.onerror = function () {
        if (ctaBtn) { ctaBtn.classList.remove('loading'); ctaBtn.disabled = false; }
        twinShowError('Network error. Please check your connection and try again.');
    };

    xhr.ontimeout = function () {
        if (ctaBtn) { ctaBtn.classList.remove('loading'); ctaBtn.disabled = false; }
        twinShowError('Request timed out. Please try again.');
    };

    xhr.send(fd);
    return false;
}

// ── Result panel ──────────────────────────────────────────────────────────────
function twinShowResult(imageUrl, vibe, format, details) {
    var card = document.getElementById('twin-create-card');
    if (!card) return;

    var vibeLabel = {
        'everyday': 'Everyday', 'soft-glam': 'Soft Glam', 'luxury': 'Luxury',
        'editorial': 'Editorial', 'fantasy': 'Fantasy', 'afro-futurist': 'Afro Futurist'
    }[vibe] || vibe;

    var detailsHtml = details
        ? '<div class="twin-used-details"><strong>Details used:</strong> ' + twinEsc(details) + '</div>'
        : '';

    card.innerHTML = ''
        + '<div class="twin-card-header"><i class="fa-solid fa-wand-magic-sparkles"></i> Your AI Twin</div>'
        + '<div class="twin-result-panel">'
        + '  <div class="twin-result-image-col">'
        + '    <img src="' + twinEsc(imageUrl) + '" alt="AI Twin">'
        + '    <button type="button" class="twin-download-btn" onclick="twinDownload(\'' + twinEsc(imageUrl) + '\')">'
        + '      <i class="fa-solid fa-download"></i> Download'
        + '    </button>'
        + '  </div>'
        + '  <div class="twin-result-meta-col">'
        + '    <h3>Your AI Twin</h3>'
        + '    <ul class="twin-metadata-block">'
        + '      <li><b>Vibe</b><span>' + twinEsc(vibeLabel) + '</span></li>'
        + '      <li><b>Format</b><span>' + twinEsc(format) + '</span></li>'
        + '      <li><b>Source Photo</b><span>1 uploaded</span></li>'
        + '      <li><b>Status</b><span>Ready</span></li>'
        + '    </ul>'
        + '    <div class="twin-actions">'
        + '      <button type="button" class="twin-action-btn primary" onclick="twinSave(this, \'' + twinEsc(imageUrl) + '\')">'
        + '        <i class="fa-solid fa-bookmark"></i> Save My Twin'
        + '      </button>'
        + '      <button type="button" class="twin-action-btn" onclick="twinMakeMore(this)">'
        + '        <i class="fa-solid fa-rotate-right"></i> Make More Like This'
        + '      </button>'
        + '      <button type="button" class="twin-action-btn" onclick="twinTryAnotherVibe()">'
        + '        <i class="fa-solid fa-swatchbook"></i> Try Another Vibe'
        + '      </button>'
        + '      <button type="button" class="twin-action-btn" disabled title="Coming soon">'
        + '        <i class="fa-regular fa-pen-to-square"></i> Edit Twin <small style="opacity:.6">(soon)</small>'
        + '      </button>'
        + '    </div>'
        + detailsHtml
        + '  </div>'
        + '</div>';
}

// ── Download (blob fetch — works for cross-origin Fal CDN URLs) ───────────────
function twinDownload(url) {
    fetch(url)
        .then(function (r) { return r.blob(); })
        .then(function (blob) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'ai-twin-' + Date.now() + '.jpg';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(a.href);
        })
        .catch(function () {
            window.open(url, '_blank');
        });
}

// ── Make More Like This ───────────────────────────────────────────────────────
function twinMakeMore(btn) {
    if (!twinLastPayload.ref_image_b64) return;
    var card = document.getElementById('twin-create-card');
    if (!card) return;

    var origText = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating…'; btn.disabled = true; }

    var fd = new FormData();
    fd.append('qa_operation',  'twingenerate');
    fd.append('qa_request',    'aitwin');
    fd.append('qa_root',       TWIN_QA_ROOT);
    fd.append('twin_vibe',     twinLastPayload.twin_vibe);
    fd.append('twin_details',  twinLastPayload.twin_details);
    fd.append('aspect_ratio',  twinLastPayload.aspect_ratio);
    fd.append('ref_image_b64', twinLastPayload.ref_image_b64);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', TWIN_AJAX_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 120000;

    xhr.onload = function () {
        if (btn) { btn.innerHTML = origText; btn.disabled = false; }
        try {
            var data = JSON.parse((xhr.responseText || '').trim());
            if (data.status === 'success' && data.image_url) {
                twinLastImageUrl = data.image_url;
                twinShowResult(data.image_url, data.vibe || twinLastVibe, data.format || twinLastFormat, twinLastPayload.twin_details);
            } else {
                alert(data.message || 'Generation failed. Please try again.');
            }
        } catch (e) { alert('Unexpected server response.'); }
    };
    xhr.onerror = xhr.ontimeout = function () {
        if (btn) { btn.innerHTML = origText; btn.disabled = false; }
        alert('Network error. Please try again.');
    };
    xhr.send(fd);
}

// ── Try Another Vibe ─────────────────────────────────────────────────────────
// Resets the card to the form view while keeping the uploaded photo in memory.
function twinTryAnotherVibe() {
    var card = document.getElementById('twin-create-card');
    if (!card) { location.reload(); return; }

    // Clear vibe selection (keep photo)
    twinVibe = '';
    document.querySelectorAll('.twin-vibe-chip').forEach(function(c) { c.classList.remove('active'); });

    // Re-render the create form keeping the photo chip visible
    card.innerHTML = ''
        + '<div class="twin-card-header"><i class="fa-solid fa-wand-magic-sparkles"></i> Create Your Twin</div>'
        + '<div class="twin-error" id="twin-error"></div>'
        + '<p class="twin-step-label"><span class="twin-step-num">1</span> Upload Your Photo</p>'
        + '<p class="twin-step-helper">Best results: front-facing photo, good lighting, minimal blur</p>'
        + '<div class="twin-upload-area" id="twin-upload-area" style="display:none;"></div>'
        + '<div id="twin-upload-chip-wrap" style="display:' + (twinRefImageB64 ? 'block' : 'none') + ';">'
        +   '<div class="twin-upload-chip">'
        +     '<img id="twin-thumb-preview" src="' + (twinRefImageB64 || '') + '" alt="preview">'
        +     '<span class="chip-name" id="twin-chip-filename">' + (twinRefFilename || 'photo') + '</span>'
        +     '<button type="button" class="chip-remove" id="twin-remove-btn" title="Remove photo"><i class="fa-solid fa-xmark"></i></button>'
        +   '</div>'
        + '</div>'
        + '<p class="twin-step-label" style="margin-top:22px;"><span class="twin-step-num">2</span> Choose Your Twin Vibe</p>'
        + '<p class="twin-step-helper">Pick a different look and energy for your next twin</p>'
        + '<div class="twin-vibe-grid" id="twin-vibe-grid-inner">'
        + ['everyday','soft-glam','luxury','editorial','fantasy','afro-futurist'].map(function(k) {
            var labels = {everyday:'Everyday','soft-glam':'Soft Glam',luxury:'Luxury',editorial:'Editorial',fantasy:'Fantasy','afro-futurist':'Afro Futurist'};
            return '<div class="twin-vibe-chip" data-vibe="' + k + '">' + labels[k] + '</div>';
          }).join('')
        + '</div>'
        + '<p class="twin-step-label" style="margin-top:22px;"><span class="twin-step-num">3</span> Choose Format</p>'
        + '<div class="twin-format-row" id="twin-format-row-inner">'
        +   '<div class="twin-format-chip" data-ratio="1:1">Square (1:1)</div>'
        +   '<div class="twin-format-chip active" data-ratio="4:5">Portrait (4:5)</div>'
        + '</div>'
        + '<p class="twin-step-label" style="margin-top:22px;"><span class="twin-step-num">4</span> Add Details <span style="font-weight:400;opacity:.55;font-size:.85em;">(optional)</span></p>'
        + '<input type="text" id="twin-details-input" class="twin-details-input" placeholder="Example: glowing skin, natural curls, luxury editorial feel" maxlength="300">'
        + '<button type="button" id="twin-cta-btn" class="twin-cta-btn" onclick="twinGenerate(this)">'
        +   '<span class="btn-text"><i class="fa-solid fa-wand-magic-sparkles"></i> Create My AI Twin</span>'
        +   '<span class="loader"></span>'
        + '</button>'
        + '<p class="twin-cta-subtext">Your photo is already loaded — just pick a new vibe and generate.</p>';

    // Re-bind event listeners for the newly created elements
    var removeBtn = document.getElementById('twin-remove-btn');
    if (removeBtn) {
        removeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            twinRefImageB64 = '';
            twinRefFilename = '';
            var chipWrap = document.getElementById('twin-upload-chip-wrap');
            var area     = document.getElementById('twin-upload-area');
            if (chipWrap) chipWrap.style.display = 'none';
            if (area)     area.style.display     = 'block';
        });
    }
    document.querySelectorAll('.twin-vibe-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            document.querySelectorAll('.twin-vibe-chip').forEach(function(c) { c.classList.remove('active'); });
            chip.classList.add('active');
            twinVibe = chip.getAttribute('data-vibe') || '';
        });
    });
    document.querySelectorAll('.twin-format-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            document.querySelectorAll('.twin-format-chip').forEach(function(c) { c.classList.remove('active'); });
            chip.classList.add('active');
            twinFormat = chip.getAttribute('data-ratio') || '4:5';
        });
    });
    var detInp = document.getElementById('twin-details-input');
    if (detInp) detInp.addEventListener('input', function() { twinDetails = detInp.value; });
    twinDetails = '';

    // Scroll card into view
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Save Twin ─────────────────────────────────────────────────────────────────
function twinSave(btn, imageUrl) {
    var origHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…'; btn.disabled = true; }

    var fd = new FormData();
    fd.append('qa_operation', 'savetwin');
    fd.append('qa_request',   'aitwin');
    fd.append('qa_root',      TWIN_QA_ROOT);
    fd.append('image_url',    imageUrl);
    fd.append('vibe',         twinLastVibe);
    fd.append('format',       twinLastFormat);
    fd.append('details',      twinLastPayload.twin_details || '');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', TWIN_AJAX_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 20000;

    xhr.onload = function () {
        try {
            var data = JSON.parse((xhr.responseText || '').trim());
            if (data.status === 'saved') {
                if (btn) { btn.innerHTML = '<i class="fa-solid fa-check"></i> Saved!'; btn.disabled = true; }
            } else {
                if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
                alert(data.message || 'Could not save. Please try again.');
            }
        } catch (e) {
            if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
            alert('Could not save. Please try again.');
        }
    };
    xhr.onerror = xhr.ontimeout = function () {
        if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
        alert('Network error. Please try again.');
    };
    xhr.send(fd);
}

// ── Escape helper ─────────────────────────────────────────────────────────────
function twinEsc(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

JSBLOCK;
$cont .= '</script>';

// ── Return content ────────────────────────────────────────────────────────────
$qa_content['custom'] = $cont;
return $qa_content;
