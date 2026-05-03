<?php
class qa_html_theme extends qa_html_theme_base {
	public function doctype()
	{
		// Landing page outputs its own doctype inside render_landing_page()
		if ('landing' != $this->template) {
			parent::doctype();
		}
	}

	public function html()
	{
		// Landing page is completely standalone — bypass all Q2A chrome
		if ('landing' == $this->template) {
			$this->render_landing_page();
			return;
		}

		$this->output(
			'<html lang="en-US" class="king-lnight">',
			'<!-- Created by KingMedia -->'
		);
		$this->head();
		$this->body();
		$this->output(
			'<!-- Created by KingMedia with love <3 -->',
			'</html>'
		);
	}
	public function head_script()
	{
		if (isset($this->content['script'])) {
			foreach ($this->content['script'] as $scriptline)
				$this->output_raw($scriptline);
		}
		$this->output( '<script src="' . $this->rooturl . 'js/night.js"></script>' );
	}
	public function body_footer()
	{
		if (isset($this->content['body_footer'])) {
			$this->output_raw($this->content['body_footer']);
		}

		// ── Global free-limit upgrade modal ────────────────────────────────────
		$membership_url = qa_path_html('membership');
		$this->output(
			'<div id="ebx-upgrade-modal" class="ebx-upgrade-modal" style="display:none;" role="dialog" aria-modal="true">',
			'<div class="ebx-upgrade-modal-inner">',
			'<div class="ebx-upgrade-modal-icon"><i class="fa-solid fa-rocket"></i></div>',
			'<h2>You\'ve used your 2 free generations!</h2>',
			'<p>Upgrade to keep creating and unlock AI Twin, video, and more.</p>',
			'<a href="' . $membership_url . '" class="ebx-cta-btn ebx-cta-primary"><i class="fa-solid fa-arrow-up"></i> Upgrade Now</a>',
			'<button class="ebx-upgrade-modal-dismiss" onclick="document.getElementById(\'ebx-upgrade-modal\').style.display=\'none\'">Maybe Later</button>',
			'</div>',
			'</div>',
			'<script>',
			'function ebxShowUpgradeModal(){var m=document.getElementById("ebx-upgrade-modal");if(m)m.style.display="flex";}',
			'</script>'
		);

		$this->output( '<link rel="preconnect" href="https://fonts.googleapis.com">' );
		$this->output( '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' );
		$this->output( '<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600&display=swap" rel="stylesheet">' );

		$this->king_js_codes();
		
	}
	public function king_js() {
		$this->output( '<script src="' . $this->rooturl . 'js/main.min.js"></script>' );
		$this->output( '<script src="' . $this->rooturl . 'js/bootstrap.min.js"></script>' );

		if ( 'home' == $this->template || 'hot' == $this->template || 'search' == $this->template || 'updates' == $this->template || 'user-questions' == $this->template || 'favorites' == $this->template || 'qa' == $this->template || 'tag' == $this->template || 'type' == $this->template || 'reactions' == $this->template || 'aifavs' == $this->template || 'pposts' == $this->template || 'private-posts' == $this->template ) {
			$this->output( '<script src="' . $this->rooturl . 'js/jquery-ias.min.js"></script>' );
			$this->output( '<script src="' . $this->rooturl . 'js/masonry.pkgd.min.js"></script>' );
		}

	}

	public function body_content() {
		$this->body_prefix();
		$this->notices();
		$this->body_header();

		$this->output( '<DIV class="king-body" id="lmenu">' );
		$this->header();

		// ── Ebonix Gallery: inject enhanced visual styles ─────────────────────
		if ( 'home' == $this->template && qa_request() === 'gallery' ) {
			$this->output( '<style>
			/* ── Gallery page overrides ── */
			#king-body-wrapper { background: #09090b; }
			.king-body-in { background: transparent; }
			.leo-nav { background: transparent; border-bottom: 1px solid rgba(255,255,255,0.06); margin-bottom: 0; padding: 16px 24px; }
			.leo-nav .king-tabs-filter { gap: 8px; }
			.leo-nav .king-tabs-filter a,
			.leo-nav .king-tabs-filter li a { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08); color: #a1a1aa; border-radius: 999px; padding: 6px 18px; font-size: 13px; font-weight: 500; transition: all .2s; }
			.leo-nav .king-tabs-filter a:hover,
			.leo-nav .king-tabs-filter li a:hover { background: rgba(139,92,246,0.18); border-color: rgba(139,92,246,0.4); color: #fff; }
			.leo-nav .king-tabs-filter li.qa-nav-selected a,
			.leo-nav .king-tabs-filter .king-active a { background: linear-gradient(135deg, #7c3aed, #a855f7); border-color: transparent; color: #fff; box-shadow: 0 0 14px rgba(139,92,246,0.45); }
			/* Grid */
			#container { padding: 0; }
			.king-q-list { padding: 0; }
			.container { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 3px; padding: 3px; }
			/* Cards */
			.box.king-q-list-item { border-radius: 0; overflow: hidden; background: #18181b; border: none; margin: 0; box-shadow: none; position: relative; transition: transform .3s cubic-bezier(.4,0,.2,1), box-shadow .3s; }
			.box.king-q-list-item:hover { transform: scale(1.03); z-index: 10; box-shadow: 0 8px 40px rgba(0,0,0,0.7), 0 0 0 2px rgba(139,92,246,0.5); }
			.king-q-item-main { padding: 0; }
			.item-a { display: block; position: relative; overflow: hidden; aspect-ratio: 1 / 1; }
			.item-img { width: 100% !important; height: 100% !important; object-fit: cover; display: block; transition: transform .4s cubic-bezier(.4,0,.2,1); }
			.box.king-q-list-item:hover .item-img { transform: scale(1.08); }
			/* Gradient overlay */
			.item-a::after { content: ""; position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,0.7) 0%, transparent 50%); opacity: 0; transition: opacity .3s; pointer-events: none; }
			.box.king-q-list-item:hover .item-a::after { opacity: 1; }
			/* Format badge */
			.king-post-upbtn { position: absolute; top: 10px; left: 10px; z-index: 5; display: flex; gap: 6px; }
			.king-post-format { background: rgba(0,0,0,0.65); backdrop-filter: blur(6px); color: #fff; border-radius: 6px; padding: 3px 10px; font-size: 11px; font-weight: 600; letter-spacing: .4px; border: 1px solid rgba(255,255,255,0.1); }
			.king-class-video .king-post-format { background: rgba(139,92,246,0.75); border-color: rgba(139,92,246,0.4); }
			.king-class-image .king-post-format { background: rgba(16,185,129,0.7); border-color: rgba(16,185,129,0.4); }
			/* Action buttons */
			.mgbutton, .ajax-popup-share, .king-listen { background: rgba(0,0,0,0.55) !important; backdrop-filter: blur(6px); border-radius: 6px !important; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; color: #fff !important; border: 1px solid rgba(255,255,255,0.1) !important; transition: background .2s !important; opacity: 0; }
			.box.king-q-list-item:hover .mgbutton,
			.box.king-q-list-item:hover .ajax-popup-share,
			.box.king-q-list-item:hover .king-listen { opacity: 1; }
			.mgbutton:hover, .ajax-popup-share:hover { background: rgba(139,92,246,0.7) !important; }
			/* Videos: auto fit */
			.king-avideo { width: 100% !important; height: 100% !important; object-fit: cover; position: absolute; inset: 0; }
			.post-featured-img { display: block; width: 100%; height: 100%; }
			/* Hide sidebar */
			.king-sidebar { display: none !important; }
			.king-main { width: 100% !important; max-width: 100% !important; }
			/* Page bg */
			body { background: #09090b !important; }
			</style>' );
		}

		if ('home' == $this->template) {
			$bg_url = qa_opt('site_url') . 'king-include/uploads/bghome.jpeg';
			$this->output( '<style>
			.king-body-search-up {
				position: relative !important;
				display: block !important;
				min-height: 700px !important;
				background-image: url("' . $bg_url . '") !important;
				background-size: cover !important;
				background-position: center center !important;
				background-repeat: no-repeat !important;
				overflow: hidden !important;
			}
			.king-body-search {
				position: absolute !important;
				left: 0 !important;
				right: 0 !important;
				top: 0 !important;
				bottom: 0 !important;
				z-index: 1 !important;
				display: flex !important;
				flex-direction: column !important;
				justify-content: center !important;
				align-items: center !important;
				text-align: center !important;
				color: #ffffff !important;
				background-image: linear-gradient(to bottom, rgba(12,11,13,0.05) 0%, rgba(12,11,13,0.35) 60%, rgba(12,11,13,0.92) 100%) !important;
			}
			.king-body-search h1 {
				color: #ffffff !important;
				font-size: 2.5rem !important;
				font-weight: 700 !important;
				margin-bottom: 8px !important;
			}
			.king-body-search h3 {
				color: rgba(255,255,255,0.8) !important;
				font-size: 1rem !important;
				font-weight: 400 !important;
				margin-bottom: 24px !important;
			}
			</style>' );
			$this->output( '<div class="king-body-search-up">' );
			$this->output( '<div class="king-body-search">' );
			$this->output( '<h1>' . qa_opt('kingh_title') . '</h1>' );
			$this->output( '<h3>' . qa_opt('kingh_desc') . '</h3>' );
			$this->nav_user_search();
			$this->output( '</div>' );
			$this->featured();
			$this->output( '</div>' );
		} else {
			$this->nav_user_search();
		}
		if ( isset( $this->content['profile'] ) ) {
			$this->profile_page();
		}
		$this->h_title();
	
		$this->output( '<DIV id="king-body-wrapper" class="king-body-in">' );
		$this->widgets( 'full', 'top' );
		$this->widgets( 'full', 'high' );
		$this->output( '<div class="leo-nav">' );
		$this->nav( 'sub' );
		if ( 'home' == $this->template || 'hot' == $this->template || 'search' == $this->template || 'updates' == $this->template || 'user-questions' == $this->template || 'favorites' == $this->template || 'qa' == $this->template || 'tag' == $this->template || 'type' == $this->template || 'reactions' == $this->template || 'aifavs' == $this->template || 'pposts' == $this->template ) {
			$this->output( '<div class="leo-range">' );
			$this->output( '<span id="range-value">4</span><i class="fa-solid fa-square"></i>' );
			$this->output( '<input id="myRange" class="leo-slider" type="range" min="2" max="10" step="1" value="4" >' );
			$this->output( '<i class="fa-solid fa-grip"></i></div>' );
		}
		$this->output( '</div>' );
		$this->nav( 'kingsub' );
		$this->widgets( 'full', 'low' );
		$this->output( '<div id="container">' );
		$this->main();
		$this->output( '</div>' );
		$this->footer();
		$this->output( '</DIV>' );
	
		$this->body_suffix();
		$this->output( '</DIV>' );
	}


	public function main() {
		$content = $this->content;
		$hidden = isset( $content['hidden'] ) ? ' king-main-hidden' : '';
		$class = isset( $content['class'] ) ? $content['class'] : ' one-page';
		$q_view = isset($content['q_view']) ? $content['q_view'] : '';
		$this->widgets( 'main', 'top' );
		$this->output( '<DIV CLASS="king-main' . $class . $hidden.'">' );
		if ( $q_view ) {
			$this->main_up($q_view);

		} else {
			$this->output( '<DIV CLASS="king-main-in">' );
			$this->widgets( 'main', 'high' );
			$this->main_parts( $content );
			$this->page_links();
			$this->output( '</div> <!-- king-main-in -->' );
			if ( isset($content['sside']) ) {
				$this->sidepanel();
			}
		}
		

		$this->output( '</DIV> <!-- king-main -->' );
		
		$this->suggest_next();
		$this->widgets('main', 'bottom');
	}


	public function main_up($q_view) {
			$content = $this->content;
			$text2 = $q_view['raw']['postformat'];
			$nsfw  = $q_view['raw']['nsfw'];
			$class= ' king-naip';
			if ( null !== $nsfw && ! qa_is_logged_in() ) {
				$this->output( '<DIV CLASS="king-video">' );
				$this->output( '<span class="king-nsfw-post"><p><i class="fas fa-mask fa-2x"></i></p>' . qa_lang_html( 'misc/nsfw_post' ) . '</span>' );
				$this->output( '</DIV>' );
				$class= ' king-aip';
			} elseif ( 'V' == $text2 ) {
				$this->output( '<DIV CLASS="king-video-in">' );
				$this->output( '<DIV CLASS="king-video">' );
				$this->q_view_extra( $q_view );
				$this->output( '</DIV>' );
				$this->output( '</DIV>' );
				$class= ' king-aip';
			} elseif ( 'music' == $text2 ) {
				$this->output( '<DIV CLASS="king-video-in">' );
				$this->output( '<DIV CLASS="king-video">' );
				$this->music_view( $q_view );
				$this->output( '</DIV>' );
				$this->output( '</DIV>' );
				$class= ' king-aip';
			} elseif ( 'I' == $text2 ) {
				$this->output( '<DIV CLASS="king-video-in">' );
				$this->output( '<DIV CLASS="king-video">' );
				$this->q_view_extra( $q_view );
				$this->output( '</DIV>' );
				$this->output( '</DIV>' );
				$class= ' king-aip';
			}
			$this->output( '<DIV CLASS="king-main-leo'.qa_html($class).'">' );
			$this->output( '<DIV CLASS="king-main-in">' );
			$this->widgets( 'main', 'high' );
			$this->main_parts( $content );
			$this->page_links();
			$this->output( '</div> <!-- king-main-in -->' );
			if ( isset($content['sside']) ) {
				$this->sidepanel();
			}
			$this->output( '</DIV>' );
			$this->viewtop();

	}

	public function q_view( $q_view ) {

		$nsfw  = $q_view['raw']['nsfw'];

		if ( ! empty( $q_view ) ) {

			
			if ( null == $nsfw || qa_is_logged_in() ) {
				$this->output( '<DIV CLASS="king-q-view' . ( @$q_view['hidden'] ? ' king-q-view-hidden' : '' ) . rtrim( ' ' . @$q_view['classes'] ) . '"' . rtrim( ' ' . @$q_view['tags'] ) . '>' );
				$this->a_count( $q_view );
				$this->output( '<DIV CLASS="rightview">' );
				$this->post_title($q_view);
				$this->postcontent($q_view);
				$this->post_tags( $q_view, 'king-q-view' );
				$this->output( '<DIV CLASS="postmeta">' );
				$this->view_count( $q_view );
				$this->q_view_buttons($q_view);
				$this->post_meta_when( $q_view, 'meta' );
				$this->output( '</DIV>' );
				if ( qa_opt( 'show_ad_post_below' ) && king_add_free_mode() ) {
					$this->output( '<div class="ad-below">' );
					$this->output( '' . qa_opt( 'ad_post_below' ) . '' );
					$this->output( '</div>' );
				}

				$this->output( '</DIV>' );

				$this->output( '</DIV> <!-- END king-q-view -->', '' );
				$this->output( '<div class="prev-next">' );
				$this->get_next_q($q_view);
				$this->get_prev_q($q_view);
				$this->output( '</div>' );
			}
			$this->socialshare($q_view);

			$this->pboxes( $q_view );
			$this->output( '<div id="commentmodal" class="king-modal-login">' );
			$this->output( '<div class="king-modal-content">' );
			$this->maincom( $q_view );
			$this->output( '</div>' );
			$this->output( '</div>' );

		}
	}
	public function q_view_extra( $q_view ) {
		if ( ! empty( $q_view['extra'] ) ) {
			require_once QA_INCLUDE_DIR . 'king-app-video.php';
			$extraz = $q_view['extra']['content'];
			$extras = @unserialize( $extraz );

			if ( $extras ) {
				$this->output('<div class="king-gallery owl-carousel">');
				foreach ( $extras as $extra ) {
					$text2 = king_get_uploads( $extra );
					$this->output('<div class="king-gallery-img">');
					$this->output('<div class="king-gallery-imgs">');
					$this->output( '<a href="' . $text2['furl'] . '">');
					$this->output( '<img class="gallery-img king-lazy" width="' . $text2['width'] . '" height="' . $text2['height'] . '" data-king-img-src="' . $text2['furl'] . '" alt=""/>' );
					
					$this->output( '</a>');
					$this->output('</div>');
					if (qa_opt('eidown')) {
						$this->output( '<a href="' . $text2['furl'] . '" class="aidown" download><button><i class="fa-solid fa-download"></i></button></a>');
					}
					
					$this->output('</div>');
				}
				$this->output('</div>');
			} elseif ( is_numeric( $extraz ) ) {
				$vidurl = king_get_uploads( $extraz );
				$thumb  = $this->content['description'];
				$poster = king_get_uploads( $thumb );
				$this->output('<video id="my-video" class="video-js vjs-theme-forest" controls preload="auto" autoplay width="960" height="540" data-setup="{}" poster="' . $poster['furl'] . '" >');
				$this->output('<source src="' . $vidurl['furl'] . '" type="video/mp4" />');
				$this->output('</video>');
			} else {
				if ( $extraz ) {
					$this->output_raw( $extraz = embed_replace( $extraz ) );
				}
			}
		}
	}
	public function viewtop()
	{
		$q_view   = @$this->content['q_view'];
		$favorite = @$this->content['favorite'];

		if ($this->template == 'question') {
			$this->output('<DIV CLASS="share-bar scrolled-up" id="share-bar">');

			if (isset($q_view['main_form_tags'])) {
				$this->output('<FORM ' . $q_view['main_form_tags'] . '>');
			}

			$this->voting($q_view);
			if (isset($q_view['main_form_tags'])) {
				$this->form_hidden_elements(@$q_view['voting_form_hidden']);
				$this->output('</FORM>');
			}
			if (isset($favorite)) {
				$this->output('<FORM ' . $favorite['form_tags'] . '>');
			}

			$this->favorite();
			if (isset($favorite)) {
				$this->form_hidden_elements(@$favorite['form_hidden']);
				$this->output('</FORM>');
			}

			$this->output('<div class="share-link" data-toggle="modal" data-target="#sharemodal" role="button" ><i data-toggle="tooltip" data-placement="top" class="fa-regular fa-paper-plane" title="' . qa_lang_html('misc/king_share') . '"></i></div>');
			if (qa_get_logged_in_level()>=QA_USER_LEVEL_ADMIN) {
				if ($q_view['raw']['featured']) {
					$fclass=' selected';
				} else {
					$fclass=' not-selected';
				}
				$this->output('<div class="share-link addfeatured'.qa_html($fclass).'" onclick="return featuredclick(this);" data-pid="'.qa_html($q_view['raw']['postid']).'" data-toggle="tooltip" data-placement="top" title="' . qa_lang_html('misc/featured') . '"><i class="fas fa-star"></i></div>');
			}
			if (qa_opt('enable_bookmark')) {
				$this->output( post_bookmark( $q_view['raw']['postid'], 'share-link' ) );
			}
			
			$this->output('<div class="share-link" data-toggle="modal" data-target="#commentmodal" role="button" ><i data-toggle="tooltip" data-placement="top" class="fa-regular fa-comment" title="' . qa_lang_html( 'misc/postcomments' ) . '"></i></div>');
			$this->output('</DIV>');

		}


	}
	public function header() {
		$this->output( '<header CLASS="king-headerf" id="king-header">' );
		$this->output( '<DIV CLASS="king-header">' );
		$this->header_left();
		$this->header_middle();
		$this->header_right();
		$this->output( '</DIV>' );
		$this->leftmenu();

		$this->output( '</header>' );
				



		if ( isset( $this->content['error'] ) ) {
			$this->error( @$this->content['error'] );
		}
	}
	public function search()
	{
		$search = $this->content['search'];

		$this->output('<div class="king-search">');
		$this->output('<div class="king-search-in">');
		$this->output('<form ' . qa_sanitize_html($search['form_tags']) . '>',
			qa_sanitize_html($search['form_extra'])
		);

		$this->search_field($search);
		$this->search_button($search);

		$this->output('</form>');
		$populartags=qa_db_single_select(qa_db_popular_tags_selectspec(0, 5));
		$this->output('<div class="search-disc">');
		$this->output('<h3>'.qa_lang_html('misc/discover').'</h3>');
		foreach ($populartags as $tag => $count) {
			$this->output('<a class="disc-tags" href="'.qa_path_html('tag/'.$tag).'" >'.qa_html($tag).'</a>');
		}
		$this->output('</div>');
		$this->output('<div id="king_live_results" class="liveresults">');
		$this->output('</div>');
		$this->output('</div>');
		$this->output('</div>');
	}
	public function search_field($search)
	{
		$this->output('<input type="text" '.$search['field_tags'].' value="'.@$search['value'].'" class="king-search-field" placeholder="'.qa_lang_html('misc/search').'" autocomplete="off"/>');
	}
	public function h_title() {
		if (isset( $this->content['header'] ) ) {
			$this->output( '<div class="head-title">' );
			$this->output( $this->content['header'] );
			$this->output( '</div>' );
		}
	}
	public function header_left() {
		$this->output( '<div class="header-left">' );
		$this->output( '<button id="ltoggle"></button>' );
		$this->logo();
		$this->output( '</div>' );
	}

	public function header_middle() {
		$this->output( '<div class="header-middle">' );
		// Ebonix AI-focused navigation — replaces Q2A community links
		$this->output( '<nav class="ebx-main-nav">' );
		if ( qa_opt('king_leo_enable') ) {
			$active_img = (qa_request() === 'submitai') ? ' ebx-nav-active' : '';
			$this->output( '<a href="' . qa_path_html('submitai') . '" class="ebx-nav-link' . $active_img . '"><i class="fa-regular fa-image"></i> AI Image</a>' );
		}
		if ( qa_opt('enable_aivideo') ) {
			$active_vid = (qa_request() === 'videoai') ? ' ebx-nav-active' : '';
			$this->output( '<a href="' . qa_path_html('videoai') . '" class="ebx-nav-link' . $active_vid . '"><i class="fa-regular fa-circle-play"></i> AI Video</a>' );
		}
		if ( qa_opt('enable_aitwin') ) {
			$active_twin = (qa_request() === 'aitwin') ? ' ebx-nav-active' : '';
			$this->output( '<a href="' . qa_path_html('aitwin') . '" class="ebx-nav-link' . $active_twin . '"><i class="fa-regular fa-user-circle"></i> AI Twin</a>' );
		}
		$active_plan = (qa_request() === 'myplan') ? ' ebx-nav-active' : '';
		if ( qa_is_logged_in() ) {
			$this->output( '<a href="' . qa_path_html('myplan') . '" class="ebx-nav-link' . $active_plan . '"><i class="fa-solid fa-coins"></i> My Plan</a>' );
		}
		$active_gallery = (qa_request() === 'gallery') ? ' ebx-nav-active' : '';
		$this->output( '<a href="' . qa_path_html('gallery') . '" class="ebx-nav-link' . $active_gallery . '"><i class="fa-regular fa-images"></i> Gallery</a>' );
		$active_mem = (qa_request() === 'membership') ? ' ebx-nav-active' : '';
		$this->output( '<a href="' . qa_path_html('membership') . '" class="ebx-nav-link' . $active_mem . '"><i class="fa-solid fa-bolt"></i> Plans</a>' );
		$this->output( '</nav>' );
		$this->output('</div>');
	}

	public function header_right() {
		$this->output( '<DIV CLASS="header-right">' );
		$this->output( '<ul>' );

		if ( qa_is_logged_in() ) {
			$this->userpanel();
			// ── Coin balance pill ───────────────────────────────────────────
			if (!function_exists('ebonix_get_coins')) {
				require_once QA_INCLUDE_DIR . 'king-app/coins.php';
			}
			$_nav_uid   = qa_get_logged_in_userid();
			$_nav_coins = $_nav_uid ? ebonix_get_coins($_nav_uid) : 0;
			$this->output( '<li class="ebonix-coin-li">' );
			$this->output( '<a href="' . qa_path_html('myplan') . '" class="ebonix-coin-balance" id="ebonix-coin-display" title="Your coin balance">' );
			$this->output( '<span class="coin-icon"><i class="fa-solid fa-coins"></i></span>' );
			$this->output( '<span class="coin-count" id="ebonix-coin-count">' . number_format($_nav_coins) . ' coins</span>' );
			$this->output( '</a>' );
			$this->output( '</li>' );
		}

		if (  ( qa_user_maximum_permit_error( 'permit_post_q' ) != 'level' ) && !qa_opt('hsubmit') ) {
			$this->kingsubmit();
		} else {
			$this->kingsubmitai();
		}


		$this->output( '<li class="search-button"><span data-toggle="dropdown" data-target=".king-search" aria-expanded="false" class="search-toggle"><i class="fas fa-search fa-lg"></i></span></li>' );
		$this->output( '</ul>' );
		$this->output( '</DIV>' );
	}

public function kingsubmitai() {
		if ( qa_opt( 'king_leo_enable' ) && qa_opt( 'enable_aivideo' ) ) {
			$this->output( '<li>' );
			$this->output( '<div class="king-submit">' );

			$this->output( '<span class="aisubmit" data-toggle="dropdown" data-target=".king-submit" aria-expanded="false" role="button"><i class="fa-solid fa-feather-pointed"></i>'.qa_lang('kingai_lang/aisubmit').'</span>' );
			$this->output( '<div class="king-dropdown2">' );
			if ( qa_opt( 'king_leo_enable' ) ) {
				$this->output( '<a href="' . qa_path_html( 'submitai' ) . '" class="kingaddai"><i class="fa-solid fa-atom"></i> ' . qa_lang_html( 'misc/king_ai' ) . '</a>' );
			}
			if ( qa_opt( 'enable_aivideo' ) ) {
				$this->output( '<a href="' . qa_path_html( 'videoai' ) . '" class="kingaddai"><i class="fa-solid fa-atom"></i> ' . qa_lang_html( 'misc/king_aivid' ) . '</a>' );
			}	
			$this->output( '</div>' );
			$this->output( '</div>' );
			$this->output( '</li>' );
		} else {
			$this->output( '<li>' );
			$this->output( '<a class="aisubmit" href="' . ( qa_opt( 'enable_aivideo' ) ? qa_path_html( 'videoai' ) : qa_path_html( 'submitai' ) ) . '"><i class="fa-solid fa-feather-pointed"></i> ' . qa_lang_html( 'kingai_lang/aisubmit' ) . '</a>' );
			$this->output( '</li>' );
		}
	}


	public function userpanel() {
		$userid = qa_get_logged_in_userid();
		if (qa_opt('enable_bookmark')) {
			require_once QA_INCLUDE_DIR . 'king-db/metas.php';
			
			$rlposts  = qa_db_usermeta_get( $userid, 'bookmarks' );
			$result = $rlposts ? unserialize( $rlposts ) : '';
			$count   = ! empty( $result ) ? count( $result ) : 0;
		}


		if (qa_opt('enable_bookmark')) {
			$this->output( '<li>' );
			$this->output( '<div class="king-rlater" data-toggle="modal" data-target="#rlatermodal" onclick="return bookmodal();">
						<i class="fa-solid fa-bookmark"></i>
						<input type="hidden" class="king-bmcountin" id="bcount" value="' . qa_html( $count ) . '" />
						<span class="king-bmcount" id="bcounter">' . qa_html( $count ) . '</span>
					</div>' );
			$this->output( '</li>' );
		}
	}
	public function leftmenu() {
		$this->output( '<div class="leftmenu kingscroll">' );

		$this->output('<button type="button" class="king-left-close" data-target=".leftcats, .leftmenu" data-toggle="dropdown"  aria-expanded="false"></button>');
		$this->nav_main_sub();
		$this->output( '<div class="usrleft">' );
		if ( ! qa_is_logged_in() ) {
			
			$this->output( '<div class="reglink" data-toggle="modal" data-target="#loginmodal" role="button" title="' . qa_lang_html( 'main/nav_login' ) . '"><i class="fa-solid fa-user"></i></div>' );
			
		} else {
			$this->output( '<div class="king-havatar" data-toggle="dropdown" data-target=".king-dropdown, .leftmenu" aria-expanded="false" >' );
			$this->output( get_avatar( qa_get_logged_in_user_field('avatarblobid'), 40 ) );
			$this->output( '</div>' );
		}
		$this->output( '</div>' );
		$this->output( '<input type="checkbox" id="king-lnight" class="hide" /><label for="king-lnight" class="king-nightb"><i class="fa-solid fa-sun"></i><i class="fa-solid fa-moon"></i></label>' );
		$this->output( '</div>' );
		$this->output( '<div class="king-mega-menu leftcats">' );
		if ( qa_using_categories() ) {
			$this->king_cats();
		}
		$this->nav('headmenu');
		$this->output('</div>');
		if ( qa_is_logged_in() ) {
		$userid = qa_get_logged_in_userid();
		$this->output( '<div class="king-dropdown king-mega-menu">' );
		$this->output( '<div>' );
		$this->output( '<a href="' . qa_path_html('user/'.qa_get_logged_in_user_field('handle')) . '" ><h3>' . qa_get_logged_in_user_field('handle') . '</h3></a>' );
		$this->output( '<span class="user-box-point"><strong>' . qa_html( number_format( qa_get_logged_in_user_field('points') ) ) . '</strong> ' . qa_lang_html( 'admin/points_title' ) . '</span>' );
		$this->output(membership_badge($userid));

		$this->nav( 'user' );
		$this->output( '<div class="king-myplan-link"><a href="' . qa_path_html('myplan') . '"><i class="fa-solid fa-id-card"></i> My Plan</a></div>' );
		$this->output( '</div>' );
		if ( qa_opt('ailimits') || qa_opt('ulimits') ) {
			require_once QA_INCLUDE_DIR.'king-db/metas.php';
			$mp  = qa_db_usermeta_get( $userid, 'membership_plan' );
			$pl = null;
			if ($mp) {
				$pl = (INT)qa_opt('plan_'.$mp.'_lmt');
			} elseif (qa_opt('ulimits')) {
				$pl = (INT)qa_opt('ulimit');
			}
			$alm = (INT)qa_db_usermeta_get( $userid, 'ailmt' );
			if ($pl) {
				$perc = ( $alm*100 ) / $pl;
				$this->output( '<div class="ailimit">' );
				$this->output( '<h5>'.qa_lang('kingai_lang/credits').'</h5>' );
				$this->output( '<h4>'.$alm.' / '.$pl.'</h4>' );
				$this->output( '<div class="ailimits"><span style="width:'.$perc.'%;"></span></div>' );
				$this->output( '</div>' );
			}
		}
		$this->output( '</div>' );
		}

	}

	public function nav_main_sub() {
		$this->output( '<DIV CLASS="king-nav-main">' );
		$this->nav( 'main' );
		$this->output( '</DIV>' );
	}

	public function profile_page() {
		$handle = qa_request_part( 1 );

		if ( ! strlen( (string)$handle ) ) {
			$handle = qa_get_logged_in_handle();
		}

		$user = qa_db_select_with_pending(
			qa_db_user_account_selectspec( $handle, false )
		);

		$this->output( get_user_html( $user, '1200', 'king-profile', '140' ) );
	}



	/**
	 * @param $q_items
	 */
	public function q_list_items( $q_items ) {

		$this->output( '<div class="container">' );
		foreach ( $q_items as $q_item ) {
			$this->q_list_item( $q_item );
		}
		$this->output( '</div>' );

	}

	/**
	 * @param $q_item
	 */
	public function q_list_item( $q_item ) {
		// Skip posts with no image/content — they create empty boxes in gallery
		if ( empty( $q_item['raw']['content'] ) ) {
			return;
		}

		$format     = $q_item['raw']['postformat'];
		$postformat = '';
		$postc      = '';
		$shomag     = true;

		if ( 'V' == $format ) {
			$postformat = '<a class="king-post-format" href="' . qa_path_html( 'type' ) . '"><i class="fa-solid fa-play"></i> ' . qa_lang_html( 'main/video' ) . '</a>';
			$postc      = ' king-class-video';
		} elseif ( 'I' == $format ) {
			$postformat = '<a class="king-post-format" href="' . qa_path_html( 'type', array( 'by' => 'images' ) ) . '"><i class="fas fa-image"></i> ' . qa_lang_html( 'main/image' ) . '</a>';
			$postc      = ' king-class-image';
		} elseif ( 'N' == $format ) {
			$postformat = '<a class="king-post-format" href="' . qa_path_html( 'type', array( 'by' => 'news' ) ) . '"><i class="fas fa-newspaper"></i> ' . qa_lang_html( 'main/news' ) . '</a>';
			$postc      = ' king-class-news';
		} elseif ( 'poll' == $format ) {
			$postformat = '<a class="king-post-format" href="' . qa_path_html( 'type', array( 'by' => 'poll' ) ) . '"><i class="fas fa-align-left"></i> ' . qa_lang_html( 'main/poll' ) . '</a>';
			$postc      = ' king-class-poll';
		} elseif ( 'list' == $format ) {
			$postformat = '<a class="king-post-format" href="' . qa_path_html( 'type', array( 'by' => 'list' ) ) . '"><i class="fas fa-bars"></i> ' . qa_lang_html( 'main/list' ) . '</a>';
			$postc      = ' king-class-list';
		} elseif ( 'trivia' == $format ) {
			$postformat = '<a class="king-post-format" href="' . qa_path_html( 'type', array( 'by' => 'trivia' ) ) . '"><i class="fas fa-times"></i> ' . qa_lang_html( 'main/trivia' ) . '</a>';
			$postc      = ' king-class-trivia';
		} elseif ( 'music' == $format ) {
			$postformat = '<a class="king-post-format" href="' . qa_path_html( 'type', array( 'by' => 'music' ) ) . '"><i class="fas fa-headphones-alt"></i> ' . qa_lang_html( 'main/music' ) . '</a>';
			$shomag     = false;
			$postc      = ' king-class-music';

			if ( $q_item['ext'] ) {
				$shomag = true;
			}
		}

		$this->output( '<div class="box king-q-list-item' . rtrim( ' ' . @$q_item['classes'] ) . '' . $postc . '" ' . @$q_item['tags'] . '>' );
		
		$this->output( '<div class="king-post-upbtn">' );
		$this->output( $postformat );
		if ( $shomag ) {
			$this->output( '<a href="' . $q_item['url'] . '" class="ajax-popup-link magnefic-button mgbutton" data-toggle="tooltip" data-placement="bottom" title="' . qa_lang_html( 'misc/king_qview' ) . '"><i class="fas fa-search"></i></a>' );
		} else {
			$this->output( '<a href="' . $q_item['url'] . '" class="king-listen magnefic-button mgbutton" data-toggle="tooltip" data-placement="bottom" title="' . qa_lang_html( 'main/listen' ) . '"><i class="fa-solid fa-headphones"></i></a>' );
		}	
		if (qa_opt('enable_bookmark')) {
			$this->output( post_bookmark( $q_item['raw']['postid'] ) );
		}
		$this->output( '<a href="' . $q_item['url'] . '" class="ajax-popup-share magnefic-button" data-toggle="tooltip" data-placement="bottom" title="' . qa_lang_html( 'misc/king_share' ) . '"><i class="fas fa-share-alt"></i></a>' );
		$this->output( '</div>' );

		$this->q_item_main( $q_item, $format );
		$this->output( '</div>' );
	}

	/**
	 * @param $q_item
	 */
	public function q_item_main( $q_item, $postformat = null ) {
		$this->output( '<div class="king-q-item-main">' );
		$this->q_item_content( $q_item, $postformat );
		$this->output( '</div>' );
	}

	/**
	 * @param $q_item
	 */
	public function q_item_content( $q_item, $postformat = null ) {
		$text = $q_item['raw']['content'];
		$nsfw = $q_item['raw']['nsfw'];
		if ( $postformat === 'V') {
			$extra  = qa_db_postmeta_get( $q_item['raw']['postid'], 'qa_q_extra' );
		} else {
			$extra = null;
		}
		if ( null !== $nsfw && ! qa_is_logged_in() ) {
			$this->output( '<a href="' . $q_item['url'] . '" class="item-a"><span class="king-nsfw-post"><p><i class="fas fa-mask fa-2x"></i></p>' . qa_lang_html( 'misc/nsfw_post' ) . '</span></a>' );
		} elseif ( ! empty( $text ) ) {
			$text2 = king_get_uploads( $text );
			$this->output( '<A class="item-a" HREF="' . $q_item['url'] . '">');
			if ( $postformat === 'V' && is_numeric( $extra ) ) {
				$this->output( '<A class="item-a king-pvideo" HREF="' . $q_item['url'] . '">');
				$this->output_raw( '<span class="post-featured-img"><img class="item-img king-lazy" width="' . $text2['width'] . '" height="' . $text2['height'] . '" data-king-img-src="' . $text2['furl'] . '" alt=""/></span>' );
				$vidurl = king_get_uploads( $extra );
				$this->output( '<video class="king-avideo" autoplay loop muted playsinline width="'. qa_html($text2['width']) . '"
            height="'. qa_html($text2['height']) . '">
            <source data-src="' . $vidurl['furl'] . '" type="video/mp4">
            </source>
        </video>' );

			} else {
				$this->output( '<A class="item-a" HREF="' . $q_item['url'] . '">');
				$this->output_raw( '<span class="post-featured-img"><img class="item-img king-lazy" width="' . $text2['width'] . '" height="' . $text2['height'] . '" data-king-img-src="' . $text2['furl'] . '" alt=""/></span>' );

			}
			
			
			$this->output( '</A>' );
		} else {
			$this->output( '<a href="' . $q_item['url'] . '" class="king-nothumb"></a>' );
		}
	}

	public function post_title( $q_view ) {
		$this->post_meta_where( $q_view, 'metah' );
		$this->output( '<DIV CLASS="pheader">' );
		$this->output( '<H1>' );
		$this->title();
		$this->output( '</H1>' );
		$this->output( '</DIV>' );
	}

	public function get_prev_q($q_view) {

		$myurl = $q_view['raw']['postid'];
		$query_p = "SELECT *
				FROM ^posts
				WHERE postid < $myurl
				AND type='Q'
				ORDER BY postid DESC
				LIMIT 1";

		$next_link = qa_db_read_one_assoc( qa_db_query_sub( $query_p ), true );
		if ($next_link) {
		$title = $next_link['title'];
		$pid   = $next_link['postid'];
		$cont = king_get_uploads( $next_link['content'] );
		$this->output( '<A HREF="' . qa_q_path_html( $pid, $title ) . '" CLASS="king-prev-q"><div class="pnimg" style="background-image:url(' . qa_html( isset( $cont['furl'] ) ? $cont['furl'] : '' ) . ');" ></div><span>' . $title . ' <i class="fas fa-angle-right"></i></span></A>' );
		}

	}

	public function get_next_q($q_view) {

		$myurl = $q_view['raw']['postid'];
		$query_n = "SELECT *
				FROM ^posts
				WHERE postid > $myurl
				AND type='Q'
				ORDER BY postid ASC
				LIMIT 1";

		$next_link = qa_db_read_one_assoc( qa_db_query_sub( $query_n ), true );
		if ($next_link) {
		$cont = king_get_uploads( $next_link['content'] );
		$title = $next_link['title'];
		$pid   = $next_link['postid'];
		$this->output( '<A HREF="' . qa_q_path_html( $pid, $title ) . '" CLASS="king-next-q"><div class="pnimg" style="background-image:url(' . qa_html( isset( $cont['furl'] ) ? $cont['furl'] : '' ) . ');" ></div><span><i class="fas fa-angle-left"></i> ' . $title . '</span></A>' );
		}

	}

	// ─────────────────────────────────────────────────────────────────────────
	// LANDING PAGE — standalone renderer (no Q2A chrome)
	// ─────────────────────────────────────────────────────────────────────────
	public function render_landing_page() {
		$site_url       = qa_opt('site_url');
		$register_url   = qa_path_html('register');
		$login_url      = qa_path_html('login');
		$submitai_url   = qa_path_html('submitai');
		$videoai_url    = qa_path_html('videoai');
		$aitwin_url     = qa_path_html('aitwin');
		$membership_url = qa_path_html('membership');
		$gallery_url    = qa_path_html('gallery');
		$home_url       = $site_url;
		$is_logged_in   = qa_is_logged_in();
		$cta_url        = $is_logged_in ? $submitai_url : $register_url;
		$cta_label      = $is_logged_in ? 'Start Creating' : 'Get Started Free';
		$login_label    = $is_logged_in ? '' : '<a href="' . $login_url . '" class="ebx-lp-login">Sign In</a>';

		// Gallery images passed from controller
		$images = isset($this->content['landing_images']) ? $this->content['landing_images'] : [];

		// Flex plan price
		$flex_price = qa_opt('flex_plan_price') ? qa_opt('flex_plan_price') : '29';

		echo '<!DOCTYPE html><html lang="en-US">';
		echo '<head>';
		echo '<meta charset="UTF-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
		echo '<title>Ebonix &mdash; The AI Built For Black Culture</title>';
		echo '<meta name="description" content="Generate images, chat in your voice, and create content with an AI that actually understands Black features, tone, and culture.">';
		echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
		echo '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">';
		echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">';
		echo '<style>';
		echo $this->landing_page_css();
		echo '</style>';
		echo '</head>';
		echo '<body class="ebx-lp-body">';

		// ── NAVBAR ──────────────────────────────────────────────────────────
		echo '<nav class="ebx-lp-nav">';
		echo '<div class="ebx-lp-nav-inner">';
		echo '<a href="' . qa_html($site_url) . '" class="ebx-lp-logo"><span class="ebx-logo-mark">E</span><strong>Ebonix</strong></a>';
		echo '<div class="ebx-lp-nav-links">';
		echo '<a href="' . qa_html($gallery_url) . '">Gallery</a>';
		echo '<a href="' . qa_html($submitai_url) . '">AI Photos</a>';
		echo '<a href="' . qa_html($videoai_url) . '">AI Videos</a>';
		echo '<a href="' . qa_html($aitwin_url) . '">AI Twin</a>';
		echo '<a href="' . qa_html($membership_url) . '">Pricing</a>';
		echo '</div>';
		echo '<div class="ebx-lp-nav-cta">';
		echo $login_label;
		echo '<a href="' . qa_html($cta_url) . '" class="ebx-lp-btn-primary">' . qa_html($cta_label) . '</a>';
		echo '</div>';
		echo '<button class="ebx-lp-menu-toggle" onclick="document.querySelector(\'.ebx-lp-nav-links\').classList.toggle(\'open\')"><i class="fa-solid fa-bars"></i></button>';
		echo '</div>';
		echo '</nav>';

		// ── HERO ────────────────────────────────────────────────────────────
		echo '<section class="ebx-lp-hero">';
		echo '<div class="ebx-lp-grid-bg"></div>';
		echo '<div class="ebx-lp-hero-inner">';
		echo '<div class="ebx-lp-badge"><span class="ebx-badge-dot"></span> AI For Us, By Us</div>';
		echo '<h1 class="ebx-lp-headline">The AI Built<br><span class="ebx-lp-headline-accent">For Black Culture</span></h1>';
		echo '<p class="ebx-lp-subtext">Generate images, chat in your voice, and create content<br class="ebx-br-desktop"> &mdash; with an AI that actually understands your features, your tone, and your world.</p>';
		echo '<div class="ebx-lp-hero-ctas">';
		echo '<a href="' . qa_html($cta_url) . '" class="ebx-lp-btn-primary ebx-lp-btn-lg">' . qa_html($cta_label) . ' <i class="fa-solid fa-arrow-right"></i></a>';
		echo '<a href="' . qa_html($gallery_url) . '" class="ebx-lp-btn-ghost ebx-lp-btn-lg"><i class="fa-solid fa-play"></i> Explore Gallery</a>';
		echo '</div>';
		echo '</div>';

		// App preview mockup
		echo '<div class="ebx-lp-preview">';
		echo '<div class="ebx-lp-preview-card">';
		echo '<div class="ebx-lp-preview-bar"><span></span><span></span><span></span></div>';
		echo '<div class="ebx-lp-preview-body">';
		echo '<div class="ebx-preview-label ebx-label-green"><span class="ebx-dot-green"></span> AI Model Active</div>';
		echo '<div class="ebx-preview-label ebx-label-right">Ebonix Images 2.0</div>';
		echo '<div class="ebx-preview-text"><em>Creative agents that<br>make you prolific</em></div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</section>';

		// ── CAPABILITIES STRIP ─────────────────────────────────────────────
		echo '<section class="ebx-lp-caps-strip" id="capabilities">';
		echo '<p class="ebx-lp-eyebrow">WHAT EBONIX CAN DO</p>';
		echo '<div class="ebx-lp-caps-tabs">';
		$caps = ['Image Generation', 'AI Chat', 'AI Video', 'AI Twin', 'AAVE Mode', 'Market Watch'];
		foreach ($caps as $i => $cap) {
			$active = $i === 0 ? ' ebx-cap-active' : '';
			echo '<span class="ebx-cap-tab' . $active . '">' . qa_html($cap) . '</span>';
		}
		echo '</div>';
		echo '</section>';

		// ── PRODUCT FEATURE BENTO ──────────────────────────────────────────
		echo '<section class="ebx-lp-bento">';
		echo '<div class="ebx-lp-section-header">';
		echo '<p class="ebx-lp-eyebrow ebx-eyebrow-purple">PRODUCT &amp; PLATFORM</p>';
		echo '<h2>Built Different.<br>For a Reason.</h2>';
		echo '<a href="' . qa_html($submitai_url) . '" class="ebx-lp-explore-link">Explore all features <i class="fa-solid fa-chevron-right"></i></a>';
		echo '</div>';
		echo '<div class="ebx-lp-bento-grid">';

		// Big feature card — Image Gen
		echo '<div class="ebx-bento-card ebx-bento-big">';
		echo '<div class="ebx-bento-preview ebx-preview-dark">';
		echo '<div class="ebx-preview-orb"></div>';
		echo '<p class="ebx-preview-caption"><em>Creative agents that<br>make you prolific</em></p>';
		echo '</div>';
		echo '<div class="ebx-bento-info">';
		echo '<span class="ebx-tag">IMAGE GEN</span>';
		echo '<h3>Accuracy That Hits Different</h3>';
		echo '<p>Most AI tools get it wrong. Ebonix was built from the ground up to render Black features, melanin, and aesthetics with real precision.</p>';
		echo '<a href="' . qa_html($submitai_url) . '" class="ebx-bento-link">Try it <i class="fa-solid fa-arrow-up-right-from-square"></i></a>';
		echo '</div>';
		echo '</div>';

		// Small cards
		echo '<div class="ebx-bento-small-col">';

		echo '<div class="ebx-bento-card ebx-bento-small ebx-bento-dark">';
		echo '<div class="ebx-bento-small-preview"></div>';
		echo '<span class="ebx-tag">AI CHAT</span>';
		echo '<h3>Talk How You Talk</h3>';
		echo '<p>Four modes. One AI that actually understands your world.</p>';
		echo '</div>';

		echo '<div class="ebx-bento-card ebx-bento-small ebx-bento-dark">';
		echo '<div class="ebx-bento-chart"><i class="fa-solid fa-chart-line"></i></div>';
		echo '<span class="ebx-tag">MARKET WATCH</span>';
		echo '<h3>Financial Intelligence</h3>';
		echo '<p>Track stocks and crypto. Get insights in plain English &mdash; no jargon.</p>';
		echo '</div>';

		echo '</div>'; // ebx-bento-small-col
		echo '</div>'; // ebx-lp-bento-grid
		echo '</section>';

		// ── CAPABILITIES GRID ──────────────────────────────────────────────
		echo '<section class="ebx-lp-capabilities">';
		echo '<p class="ebx-lp-eyebrow ebx-eyebrow-purple">CAPABILITIES</p>';
		echo '<h2>Every Mode Built<br>With Purpose</h2>';
		echo '<div class="ebx-cap-grid">';

		$modes = [
			['icon' => '🗣️', 'tag' => 'Language AI', 'title' => 'AAVE Mode', 'desc' => 'Communicates authentically in African American Vernacular English — real tone, real culture.'],
			['icon' => '🔬', 'tag' => 'Research',    'title' => 'Deep Vibe',  'desc' => 'Research, analysis, and code — when you\'re going deep and need it to keep up.'],
			['icon' => '💼', 'tag' => 'Professional','title' => 'Code Switch','desc' => 'Professional tone for business, education, and career — clean when you need it clean.'],
			['icon' => '✨', 'tag' => 'Visual AI',   'title' => 'AI Image Gen','desc' => 'Generate portraits and visuals that actually get Black features, skin tones, and aesthetics right.'],
			['icon' => '📈', 'tag' => 'Finance AI',  'title' => 'Market Watch','desc' => 'AI-powered financial insights and asset tracking delivered in plain, accessible language.'],
			['icon' => '🎬', 'tag' => 'Video AI',    'title' => 'AI Video',    'desc' => 'Craft cinematic video prompts and concepts with a vibe that hits every time.'],
		];
		foreach ($modes as $mode) {
			echo '<div class="ebx-cap-card">';
			echo '<div class="ebx-cap-card-top">';
			echo '<span class="ebx-cap-icon">' . $mode['icon'] . '</span>';
			echo '<span class="ebx-cap-tag">' . qa_html($mode['tag']) . '</span>';
			echo '</div>';
			echo '<h4>' . qa_html($mode['title']) . '</h4>';
			echo '<p>' . qa_html($mode['desc']) . '</p>';
			echo '</div>';
		}
		echo '</div>'; // ebx-cap-grid
		echo '</section>';

		// ── GALLERY STRIP ──────────────────────────────────────────────────
		echo '<section class="ebx-lp-gallery" id="gallery">';
		echo '<div class="ebx-lp-gallery-header">';
		echo '<div>';
		echo '<p class="ebx-lp-eyebrow ebx-eyebrow-purple">GALLERY</p>';
		echo '<h2>What People<br>Are Making</h2>';
		echo '</div>';
		echo '<a href="' . qa_html($gallery_url) . '" class="ebx-lp-explore-link">Start creating <i class="fa-solid fa-chevron-right"></i></a>';
		echo '</div>';
		echo '<div class="ebx-lp-gallery-strip">';
		if (!empty($images)) {
			foreach ($images as $imgurl) {
				echo '<div class="ebx-gallery-thumb" style="background-image:url(' . qa_html($imgurl) . ')"></div>';
			}
		} else {
			// Placeholder slots
			$placeholders = ['#1a1a2e', '#16213e', '#0f3460', '#1a1a2e', '#16213e', '#0f3460'];
			foreach ($placeholders as $idx => $bg) {
				echo '<div class="ebx-gallery-thumb ebx-gallery-placeholder" style="background-color:' . $bg . '">';
				echo '<i class="fa-regular fa-image"></i>';
				echo '</div>';
			}
		}
		echo '</div>';
		echo '</section>';

		// ── WHY EBONIX ────────────────────────────────────────────────────
		echo '<section class="ebx-lp-why">';
		echo '<p class="ebx-lp-eyebrow ebx-eyebrow-purple">WHY EBONIX</p>';
		echo '<h2>Other tools exist.<br>None of them were built for you.</h2>';
		echo '<div class="ebx-why-grid">';
		$reasons = [
			['num' => '#1', 'title' => 'Culturally Accurate', 'desc' => 'Trained to understand Black aesthetics, skin tones, features, and style — not as an afterthought, but as the foundation.'],
			['num' => '#2', 'title' => 'Speaks Your Language', 'desc' => 'AAVE, Code Switch, or straight professional — Ebonix moves however you need it to move.'],
			['num' => '#3', 'title' => 'Built By Us', 'desc' => 'This isn\'t a diversity add-on. Ebonix was designed from day one with Black American culture at the center.'],
		];
		foreach ($reasons as $r) {
			echo '<div class="ebx-why-card">';
			echo '<span class="ebx-why-num">' . qa_html($r['num']) . '</span>';
			echo '<h4>' . qa_html($r['title']) . '</h4>';
			echo '<p>' . qa_html($r['desc']) . '</p>';
			echo '</div>';
		}
		echo '</div>';
		echo '</section>';

		// ── COMMUNITY ─────────────────────────────────────────────────────
		echo '<section class="ebx-lp-community" id="community">';
		echo '<p class="ebx-lp-eyebrow ebx-eyebrow-purple">COMMUNITY</p>';
		echo '<h2>Join the Movement</h2>';
		echo '<div class="ebx-community-grid">';

		echo '<div class="ebx-community-card ebx-community-img">';
		echo '<div class="ebx-community-overlay">';
		echo '<h3>Creators</h3>';
		echo '<p>A community of artists, designers, and creators building with Ebonix.</p>';
		echo '</div>';
		echo '</div>';

		echo '<div class="ebx-community-card ebx-community-dark">';
		echo '<div class="ebx-community-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>';
		echo '<h3>New Drop</h3>';
		echo '<p>Ebonix Images 2.0 is live &mdash; sharper, more accurate, more you.</p>';
		echo '<a href="' . qa_html($submitai_url) . '" class="ebx-community-link">Try it now <i class="fa-solid fa-chevron-right"></i></a>';
		echo '</div>';

		echo '</div>'; // ebx-community-grid
		echo '</section>';

		// ── PRICING ───────────────────────────────────────────────────────
		echo '<section class="ebx-lp-pricing" id="pricing">';
		echo '<p class="ebx-lp-eyebrow ebx-eyebrow-gold">PRICING</p>';
		echo '<h2>Flexible pricing for<br>how you create</h2>';
		echo '<p class="ebx-pricing-subtext">Start with a monthly membership. Use your coins across photo and video creation. Top up anytime when you want more.</p>';

		echo '<div class="ebx-pricing-grid">';

		// Free plan
		echo '<div class="ebx-pricing-card">';
		echo '<p class="ebx-plan-label">FREE</p>';
		echo '<div class="ebx-plan-price"><span class="ebx-price-num">$0</span><span class="ebx-price-period">/mo</span></div>';
		echo '<p class="ebx-plan-desc">For anyone curious about AI creativity. Start creating with no commitment.</p>';
		echo '<div class="ebx-plan-coins"><span>Starting coins</span><strong>300</strong></div>';
		echo '<ul class="ebx-plan-features">';
		echo '<li><i class="fa-solid fa-check"></i> AI Image Generation</li>';
		echo '<li><i class="fa-solid fa-check"></i> 300 one-time coins</li>';
		echo '<li><i class="fa-solid fa-check"></i> Standard quality</li>';
		echo '<li><i class="fa-solid fa-check"></i> Community gallery access</li>';
		echo '</ul>';
		echo '<a href="' . qa_html($register_url) . '" class="ebx-plan-btn ebx-plan-btn-outline">Get Started Free</a>';
		echo '</div>';

		// Flex plan
		echo '<div class="ebx-pricing-card ebx-pricing-featured">';
		echo '<div class="ebx-pricing-badge">MOST POPULAR</div>';
		echo '<p class="ebx-plan-label">FLEX</p>';
		echo '<div class="ebx-plan-price"><span class="ebx-price-num">$' . qa_html($flex_price) . '</span><span class="ebx-price-period">/mo</span></div>';
		echo '<p class="ebx-plan-desc">For active creators who want more freedom, more output, and more room to build.</p>';
		echo '<div class="ebx-plan-coins"><span>Monthly coins</span><strong>10,000</strong></div>';
		echo '<ul class="ebx-plan-features">';
		echo '<li><i class="fa-solid fa-check"></i> Everything in Free</li>';
		echo '<li><i class="fa-solid fa-check"></i> AI Twin access</li>';
		echo '<li><i class="fa-solid fa-check"></i> AI Video creation</li>';
		echo '<li><i class="fa-solid fa-check"></i> 10,000 coins every month</li>';
		echo '<li><i class="fa-solid fa-check"></i> Priority generation queue</li>';
		echo '<li><i class="fa-solid fa-check"></i> Top up anytime</li>';
		echo '</ul>';
		echo '<a href="' . qa_html($membership_url) . '" class="ebx-plan-btn ebx-plan-btn-gold">Lock In</a>';
		echo '</div>';

		echo '</div>'; // ebx-pricing-grid

		// Top-up section
		echo '<div class="ebx-topup">';
		echo '<h3>Need more coins?</h3>';
		echo '<p>Keep creating without switching plans. Top up anytime and the coins are yours instantly.</p>';
		echo '<div class="ebx-topup-grid">';
		$packs = [
			['coins' => '5,000',  'price' => '$15'],
			['coins' => '10,000', 'price' => '$30'],
			['coins' => '15,000', 'price' => '$45'],
			['coins' => '20,000', 'price' => '$60'],
			['coins' => '25,000', 'price' => '$75'],
			['coins' => '30,000', 'price' => '$90'],
		];
		foreach ($packs as $pack) {
			echo '<div class="ebx-topup-card">';
			echo '<strong>' . qa_html($pack['coins']) . '</strong>';
			echo '<span>coins</span>';
			echo '<p>' . qa_html($pack['price']) . '</p>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';

		// How it works
		echo '<div class="ebx-how-it-works">';
		echo '<p class="ebx-lp-eyebrow">HOW IT WORKS</p>';
		echo '<div class="ebx-hiw-grid">';
		$steps = [
			['num' => '01', 'title' => 'Join',    'desc' => 'Pick the monthly plan that fits your pace.'],
			['num' => '02', 'title' => 'Create',  'desc' => 'Use coins across photo and video generation inside Ebonix.'],
			['num' => '03', 'title' => 'Top Up',  'desc' => 'When you want more, add coins instantly and keep going.'],
		];
		foreach ($steps as $step) {
			echo '<div class="ebx-hiw-card">';
			echo '<span class="ebx-hiw-num">' . qa_html($step['num']) . '</span>';
			echo '<h4>' . qa_html($step['title']) . '</h4>';
			echo '<p>' . qa_html($step['desc']) . '</p>';
			echo '</div>';
		}
		echo '</div>';
		echo '<div class="ebx-coin-notes">';
		echo '<p>Coins refresh monthly with your membership.</p>';
		echo '<p>Unused monthly coins do not roll over.</p>';
		echo '<p>Top-up coins are added instantly.</p>';
		echo '<p>Premium generation modes may use more coins.</p>';
		echo '</div>';
		echo '</div>';

		echo '</section>'; // ebx-lp-pricing

		// ── FINAL CTA ─────────────────────────────────────────────────────
		echo '<section class="ebx-lp-final-cta">';
		echo '<h2>You already know<br>what you could create.</h2>';
		echo '<p>Now go make it real.</p>';
		echo '<a href="' . qa_html($cta_url) . '" class="ebx-lp-btn-primary ebx-lp-btn-lg">' . qa_html($cta_label) . ' <i class="fa-solid fa-arrow-right"></i></a>';
		echo '</section>';

		// ── FOOTER ────────────────────────────────────────────────────────
		echo '<footer class="ebx-lp-footer">';
		echo '<div class="ebx-lp-footer-inner">';

		echo '<div class="ebx-footer-brand">';
		echo '<a href="' . qa_html($site_url) . '" class="ebx-lp-logo ebx-footer-logo"><span class="ebx-logo-mark">E</span><strong>Ebonix</strong></a>';
		echo '<p>The AI platform built for Black American culture, creativity, and excellence.</p>';
		echo '</div>';

		echo '<div class="ebx-footer-links">';
		echo '<div class="ebx-footer-col"><h5>Product</h5>';
		echo '<a href="' . qa_html($submitai_url) . '">AI Image</a>';
		echo '<a href="' . qa_html($videoai_url) . '">AI Video</a>';
		echo '<a href="' . qa_html($aitwin_url) . '">AI Twin</a>';
		echo '<a href="#">AI Chat</a>';
		echo '</div>';

		echo '<div class="ebx-footer-col"><h5>Platform</h5>';
		echo '<a href="' . qa_html($membership_url) . '">Pricing</a>';
		echo '<a href="' . qa_html($gallery_url) . '">Gallery</a>';
		echo '<a href="' . qa_html($register_url) . '">Sign Up</a>';
		echo '</div>';

		echo '<div class="ebx-footer-col"><h5>Legal</h5>';
		echo '<a href="#">Privacy</a>';
		echo '<a href="#">Terms</a>';
		echo '<a href="#">Cookies</a>';
		echo '</div>';
		echo '</div>'; // ebx-footer-links

		echo '</div>'; // ebx-lp-footer-inner
		echo '<div class="ebx-footer-bottom">';
		echo '<p>&copy; ' . date('Y') . ' Ebonix. All rights reserved.</p>';
		echo '<p>AI For Us, By Us.</p>';
		echo '</div>';
		echo '</footer>';

		echo '</body></html>';
	}

	private function landing_page_css() {
		return '
		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

		.ebx-lp-body {
			background: #09090b;
			color: #f1f1f1;
			font-family: "Inter", sans-serif;
			-webkit-font-smoothing: antialiased;
			overflow-x: hidden;
		}
		h1,h2,h3,h4,h5 { font-family: "DM Sans", sans-serif; }

		a { text-decoration: none; color: inherit; }

		/* ── NAV ── */
		.ebx-lp-nav {
			position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
			background: rgba(9,9,11,0.85);
			backdrop-filter: blur(12px);
			border-bottom: 1px solid rgba(255,255,255,0.06);
		}
		.ebx-lp-nav-inner {
			max-width: 1200px; margin: 0 auto;
			display: flex; align-items: center; gap: 24px;
			padding: 0 24px; height: 64px;
		}
		.ebx-lp-logo {
			display: flex; align-items: center; gap: 8px;
			font-family: "DM Sans", sans-serif; font-weight: 700; font-size: 1.1rem;
			color: #fff;
		}
		.ebx-logo-mark {
			width: 32px; height: 32px; border-radius: 8px;
			background: linear-gradient(135deg, #7c3aed, #5b21b6);
			display: flex; align-items: center; justify-content: center;
			font-weight: 800; font-size: 0.9rem; color: #fff;
		}
		.ebx-lp-nav-links {
			display: flex; gap: 32px; margin-left: auto;
		}
		.ebx-lp-nav-links a {
			font-size: 0.9rem; color: rgba(255,255,255,0.65);
			transition: color 0.2s;
		}
		.ebx-lp-nav-links a:hover { color: #fff; }
		.ebx-lp-nav-cta { display: flex; align-items: center; gap: 12px; }
		.ebx-lp-login { font-size: 0.9rem; color: rgba(255,255,255,0.65); transition: color 0.2s; }
		.ebx-lp-login:hover { color: #fff; }

		.ebx-lp-btn-primary {
			background: linear-gradient(135deg, #7c3aed, #6d28d9);
			color: #fff; border: none; border-radius: 10px;
			padding: 10px 20px; font-size: 0.9rem; font-weight: 600;
			cursor: pointer; transition: opacity 0.2s, transform 0.15s;
			display: inline-flex; align-items: center; gap: 8px;
		}
		.ebx-lp-btn-primary:hover { opacity: 0.9; transform: translateY(-1px); color: #fff; }
		.ebx-lp-btn-ghost {
			background: rgba(255,255,255,0.07);
			color: rgba(255,255,255,0.85);
			border: 1px solid rgba(255,255,255,0.12);
			border-radius: 10px; padding: 10px 20px;
			font-size: 0.9rem; font-weight: 600; cursor: pointer;
			transition: background 0.2s; display: inline-flex; align-items: center; gap: 8px;
		}
		.ebx-lp-btn-ghost:hover { background: rgba(255,255,255,0.12); color: #fff; }
		.ebx-lp-btn-lg { padding: 14px 28px; font-size: 1rem; border-radius: 12px; }

		.ebx-lp-menu-toggle {
			display: none; background: none; border: none;
			color: #fff; font-size: 1.2rem; cursor: pointer; margin-left: auto;
		}

		/* ── HERO ── */
		.ebx-lp-hero {
			min-height: 100vh; display: flex; flex-direction: column;
			align-items: center; justify-content: center;
			text-align: center; padding: 120px 24px 80px;
			position: relative; overflow: hidden;
		}
		.ebx-lp-grid-bg {
			position: absolute; inset: 0; z-index: 0;
			background-image:
				linear-gradient(rgba(124,58,237,0.06) 1px, transparent 1px),
				linear-gradient(90deg, rgba(124,58,237,0.06) 1px, transparent 1px);
			background-size: 50px 50px;
		}
		.ebx-lp-grid-bg::after {
			content: ""; position: absolute; inset: 0;
			background: radial-gradient(ellipse 70% 50% at 50% 40%, rgba(124,58,237,0.12) 0%, transparent 70%);
		}
		.ebx-lp-hero-inner {
			position: relative; z-index: 1; max-width: 760px; margin: 0 auto;
		}
		.ebx-lp-badge {
			display: inline-flex; align-items: center; gap: 8px;
			background: rgba(124,58,237,0.2); border: 1px solid rgba(124,58,237,0.4);
			border-radius: 100px; padding: 6px 16px; font-size: 0.8rem;
			color: #c4b5fd; margin-bottom: 28px; font-weight: 500;
		}
		.ebx-badge-dot {
			width: 6px; height: 6px; border-radius: 50%;
			background: #7c3aed; display: inline-block;
		}
		.ebx-lp-headline {
			font-size: clamp(3rem, 8vw, 6rem);
			font-weight: 800; line-height: 1.05;
			color: #fff; margin-bottom: 24px;
		}
		.ebx-lp-headline-accent { color: #a78bfa; }
		.ebx-lp-subtext {
			font-size: 1.1rem; color: rgba(255,255,255,0.55);
			line-height: 1.7; max-width: 560px; margin: 0 auto 40px;
		}
		.ebx-br-desktop { display: none; }
		@media(min-width:768px){ .ebx-br-desktop { display: block; } }
		.ebx-lp-hero-ctas {
			display: flex; gap: 16px; justify-content: center; flex-wrap: wrap;
		}

		/* App preview card */
		.ebx-lp-preview {
			position: relative; z-index: 1; margin-top: 64px;
			max-width: 680px; width: 100%; margin-left: auto; margin-right: auto;
		}
		.ebx-lp-preview-card {
			background: #111113; border: 1px solid rgba(255,255,255,0.08);
			border-radius: 16px; overflow: hidden;
			box-shadow: 0 40px 80px rgba(0,0,0,0.6), 0 0 0 1px rgba(124,58,237,0.1);
		}
		.ebx-lp-preview-bar {
			display: flex; gap: 6px; padding: 12px 16px;
			border-bottom: 1px solid rgba(255,255,255,0.06);
		}
		.ebx-lp-preview-bar span {
			width: 10px; height: 10px; border-radius: 50%;
			background: rgba(255,255,255,0.12);
		}
		.ebx-lp-preview-body {
			padding: 32px 28px 28px; min-height: 200px;
			background: linear-gradient(135deg, #0f0f15 0%, #13101e 100%);
			position: relative;
		}
		.ebx-preview-label {
			display: inline-flex; align-items: center; gap: 6px;
			background: rgba(0,0,0,0.6); border-radius: 100px;
			padding: 5px 12px; font-size: 0.78rem; font-weight: 500;
		}
		.ebx-label-green { color: #4ade80; }
		.ebx-label-right {
			position: absolute; bottom: 28px; right: 28px;
			color: rgba(255,255,255,0.7);
		}
		.ebx-dot-green {
			width: 6px; height: 6px; border-radius: 50%;
			background: #4ade80; display: inline-block;
		}
		.ebx-preview-text {
			position: absolute; bottom: 60px; left: 28px;
			font-size: 1.05rem; color: rgba(255,255,255,0.6); line-height: 1.5;
			font-style: italic;
		}

		/* ── EYEBROWS ── */
		.ebx-lp-eyebrow {
			font-size: 0.72rem; font-weight: 600; letter-spacing: 0.12em;
			color: rgba(255,255,255,0.35); text-transform: uppercase; margin-bottom: 16px;
		}
		.ebx-eyebrow-purple { color: #a78bfa; }
		.ebx-eyebrow-gold   { color: #d4a853; }

		/* ── CAPS STRIP ── */
		.ebx-lp-caps-strip {
			padding: 64px 24px; text-align: center;
			border-top: 1px solid rgba(255,255,255,0.06);
			border-bottom: 1px solid rgba(255,255,255,0.06);
		}
		.ebx-lp-caps-tabs {
			display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;
			margin-top: 24px;
		}
		.ebx-cap-tab {
			padding: 8px 20px; border-radius: 100px;
			border: 1px solid rgba(255,255,255,0.1);
			font-size: 0.85rem; color: rgba(255,255,255,0.5);
			transition: all 0.2s; cursor: default;
		}
		.ebx-cap-tab.ebx-cap-active {
			background: rgba(124,58,237,0.15);
			border-color: rgba(124,58,237,0.4);
			color: #c4b5fd;
		}

		/* ── BENTO ── */
		.ebx-lp-bento {
			max-width: 1100px; margin: 0 auto; padding: 96px 24px;
		}
		.ebx-lp-section-header {
			display: flex; align-items: flex-start; gap: 24px;
			flex-wrap: wrap; margin-bottom: 40px;
		}
		.ebx-lp-section-header > div:first-child,
		.ebx-lp-section-header h2 { flex: 1; }
		.ebx-lp-section-header h2 {
			font-size: clamp(1.8rem, 4vw, 2.8rem); font-weight: 700;
			line-height: 1.15; color: #fff;
		}
		.ebx-lp-explore-link {
			font-size: 0.9rem; color: rgba(255,255,255,0.5);
			display: flex; align-items: center; gap: 6px;
			margin-top: 8px; white-space: nowrap;
			transition: color 0.2s;
		}
		.ebx-lp-explore-link:hover { color: #fff; }

		.ebx-lp-bento-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 16px;
		}
		.ebx-bento-card {
			background: #111113; border: 1px solid rgba(255,255,255,0.08);
			border-radius: 16px; overflow: hidden;
			transition: border-color 0.2s;
		}
		.ebx-bento-card:hover { border-color: rgba(124,58,237,0.3); }
		.ebx-bento-big { display: flex; flex-direction: column; }
		.ebx-bento-preview {
			min-height: 240px; background: linear-gradient(135deg, #0e0e16, #1a1030);
			position: relative; display: flex; align-items: flex-end;
		}
		.ebx-preview-orb {
			position: absolute; top: 30%; left: 50%; transform: translateX(-50%);
			width: 100px; height: 100px; border-radius: 50%;
			background: radial-gradient(circle, rgba(124,58,237,0.6), rgba(124,58,237,0.05));
			box-shadow: 0 0 60px rgba(124,58,237,0.4);
		}
		.ebx-preview-caption {
			padding: 20px; font-size: 0.85rem; color: rgba(255,255,255,0.4);
			font-style: italic; position: relative; z-index: 1;
		}
		.ebx-bento-info { padding: 24px; }
		.ebx-bento-info h3 { font-size: 1.1rem; color: #fff; margin-bottom: 8px; }
		.ebx-bento-info p { font-size: 0.85rem; color: rgba(255,255,255,0.5); line-height: 1.6; }
		.ebx-bento-link {
			display: inline-flex; align-items: center; gap: 6px;
			font-size: 0.85rem; color: #a78bfa; margin-top: 12px;
			transition: color 0.2s;
		}
		.ebx-bento-link:hover { color: #c4b5fd; }
		.ebx-tag {
			display: inline-block; font-size: 0.68rem; font-weight: 600;
			letter-spacing: 0.1em; color: #a78bfa;
			margin-bottom: 8px;
		}
		.ebx-bento-small-col { display: flex; flex-direction: column; gap: 16px; }
		.ebx-bento-small { padding: 24px; }
		.ebx-bento-small-preview {
			height: 80px; background: linear-gradient(135deg, #1a1030, #0e0e16);
			border-radius: 8px; margin-bottom: 16px;
		}
		.ebx-bento-chart {
			font-size: 2rem; color: rgba(124,58,237,0.4); margin-bottom: 16px;
		}
		.ebx-bento-small h3 { font-size: 1rem; color: #fff; margin-bottom: 6px; }
		.ebx-bento-small p { font-size: 0.82rem; color: rgba(255,255,255,0.5); line-height: 1.5; }

		/* ── CAPABILITIES GRID ── */
		.ebx-lp-capabilities {
			max-width: 1100px; margin: 0 auto; padding: 0 24px 96px;
		}
		.ebx-lp-capabilities h2 {
			font-size: clamp(1.8rem, 4vw, 2.8rem); font-weight: 700;
			color: #fff; margin-bottom: 40px; line-height: 1.15;
		}
		.ebx-cap-grid {
			display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
		}
		.ebx-cap-card {
			background: #111113; border: 1px solid rgba(255,255,255,0.08);
			border-radius: 14px; padding: 24px;
			transition: border-color 0.2s;
		}
		.ebx-cap-card:hover { border-color: rgba(124,58,237,0.3); }
		.ebx-cap-card-top {
			display: flex; align-items: center; justify-content: space-between;
			margin-bottom: 16px;
		}
		.ebx-cap-icon { font-size: 1.5rem; }
		.ebx-cap-tag {
			font-size: 0.7rem; font-weight: 600; letter-spacing: 0.08em;
			background: rgba(124,58,237,0.15); color: #c4b5fd;
			border-radius: 100px; padding: 4px 10px;
		}
		.ebx-cap-card h4 { font-size: 1rem; color: #fff; margin-bottom: 8px; }
		.ebx-cap-card p { font-size: 0.82rem; color: rgba(255,255,255,0.5); line-height: 1.55; }

		/* ── GALLERY STRIP ── */
		.ebx-lp-gallery {
			max-width: 1100px; margin: 0 auto; padding: 0 24px 96px;
		}
		.ebx-lp-gallery-header {
			display: flex; align-items: flex-start; justify-content: space-between;
			gap: 16px; margin-bottom: 32px; flex-wrap: wrap;
		}
		.ebx-lp-gallery-header h2 {
			font-size: clamp(1.6rem, 3.5vw, 2.4rem); font-weight: 700; color: #fff;
		}
		.ebx-lp-gallery-strip {
			display: flex; gap: 12px; overflow-x: auto; padding-bottom: 8px;
			scrollbar-width: none;
		}
		.ebx-lp-gallery-strip::-webkit-scrollbar { display: none; }
		.ebx-gallery-thumb {
			min-width: 160px; height: 220px; border-radius: 12px;
			background-size: cover; background-position: center;
			background-color: #1a1a2e; flex-shrink: 0;
			border: 1px solid rgba(255,255,255,0.06);
		}
		.ebx-gallery-placeholder {
			display: flex; align-items: center; justify-content: center;
			color: rgba(255,255,255,0.15); font-size: 1.8rem;
		}

		/* ── WHY EBONIX ── */
		.ebx-lp-why {
			max-width: 1100px; margin: 0 auto; padding: 0 24px 96px;
		}
		.ebx-lp-why h2 {
			font-size: clamp(1.8rem, 4vw, 2.8rem); font-weight: 700;
			color: #fff; margin-bottom: 40px; line-height: 1.2;
		}
		.ebx-why-grid {
			display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
		}
		.ebx-why-card {
			background: #111113; border: 1px solid rgba(255,255,255,0.08);
			border-radius: 14px; padding: 28px;
		}
		.ebx-why-num {
			display: block; font-size: 0.75rem; font-weight: 600;
			color: #a78bfa; margin-bottom: 12px;
		}
		.ebx-why-card h4 { font-size: 1rem; color: #fff; margin-bottom: 10px; }
		.ebx-why-card p { font-size: 0.82rem; color: rgba(255,255,255,0.5); line-height: 1.6; }

		/* ── COMMUNITY ── */
		.ebx-lp-community {
			max-width: 1100px; margin: 0 auto; padding: 0 24px 96px;
		}
		.ebx-lp-community h2 {
			font-size: clamp(1.8rem, 4vw, 2.4rem); font-weight: 700;
			color: #fff; margin-bottom: 32px;
		}
		.ebx-community-grid {
			display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
		}
		.ebx-community-card {
			border-radius: 16px; overflow: hidden; min-height: 260px;
			border: 1px solid rgba(255,255,255,0.08);
		}
		.ebx-community-img {
			background: linear-gradient(135deg, #1a1030 0%, #2d1f60 100%);
			position: relative; display: flex; align-items: flex-end;
		}
		.ebx-community-overlay {
			padding: 28px; background: linear-gradient(transparent, rgba(0,0,0,0.7));
			width: 100%;
		}
		.ebx-community-overlay h3 { color: #fff; font-size: 1.1rem; margin-bottom: 6px; }
		.ebx-community-overlay p { color: rgba(255,255,255,0.65); font-size: 0.85rem; }
		.ebx-community-dark {
			background: #111113; padding: 32px;
			display: flex; flex-direction: column; justify-content: center;
		}
		.ebx-community-icon {
			width: 48px; height: 48px; background: rgba(124,58,237,0.2);
			border-radius: 12px; display: flex; align-items: center; justify-content: center;
			color: #a78bfa; font-size: 1.2rem; margin-bottom: 20px;
		}
		.ebx-community-dark h3 { color: #fff; font-size: 1.1rem; margin-bottom: 8px; }
		.ebx-community-dark p { color: rgba(255,255,255,0.5); font-size: 0.85rem; line-height: 1.55; margin-bottom: 20px; }
		.ebx-community-link {
			color: #a78bfa; font-size: 0.85rem; font-weight: 600;
			display: inline-flex; align-items: center; gap: 6px;
			transition: color 0.2s;
		}
		.ebx-community-link:hover { color: #c4b5fd; }

		/* ── PRICING ── */
		.ebx-lp-pricing {
			padding: 96px 24px; text-align: center;
			background: linear-gradient(180deg, #09090b 0%, #0d0d12 100%);
		}
		.ebx-lp-pricing h2 {
			font-size: clamp(2rem, 5vw, 3.2rem); font-weight: 700; color: #fff;
			margin-bottom: 16px; line-height: 1.15;
		}
		.ebx-pricing-subtext {
			color: rgba(255,255,255,0.45); font-size: 0.95rem; max-width: 480px;
			margin: 0 auto 56px; line-height: 1.7;
		}
		.ebx-pricing-grid {
			display: grid; grid-template-columns: 1fr 1fr;
			gap: 20px; max-width: 760px; margin: 0 auto 64px;
		}
		.ebx-pricing-card {
			background: #111113; border: 1px solid rgba(255,255,255,0.1);
			border-radius: 16px; padding: 32px; text-align: left;
			position: relative; transition: border-color 0.2s;
		}
		.ebx-pricing-featured {
			border-color: rgba(212,168,83,0.4);
			background: linear-gradient(135deg, #13100a, #111113);
		}
		.ebx-pricing-badge {
			position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
			background: #d4a853; color: #0a0700; font-size: 0.7rem; font-weight: 700;
			letter-spacing: 0.08em; padding: 4px 14px; border-radius: 100px;
			white-space: nowrap;
		}
		.ebx-plan-label {
			font-size: 0.72rem; font-weight: 600; letter-spacing: 0.12em;
			color: rgba(255,255,255,0.35); margin-bottom: 12px;
		}
		.ebx-plan-price { margin-bottom: 12px; }
		.ebx-price-num { font-size: 2.4rem; font-weight: 800; color: #fff; font-family: "DM Sans", sans-serif; }
		.ebx-price-period { font-size: 0.9rem; color: rgba(255,255,255,0.45); margin-left: 4px; }
		.ebx-plan-desc {
			font-size: 0.82rem; color: rgba(255,255,255,0.45); line-height: 1.6;
			margin-bottom: 20px;
		}
		.ebx-plan-coins {
			display: flex; justify-content: space-between; align-items: center;
			background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);
			border-radius: 10px; padding: 12px 16px;
			font-size: 0.82rem; color: rgba(255,255,255,0.5); margin-bottom: 24px;
		}
		.ebx-plan-coins strong { color: #fff; font-size: 1rem; }
		.ebx-plan-features {
			list-style: none; margin-bottom: 28px;
		}
		.ebx-plan-features li {
			font-size: 0.83rem; color: rgba(255,255,255,0.6);
			padding: 6px 0; display: flex; align-items: center; gap: 10px;
		}
		.ebx-plan-features li i { color: #7c3aed; font-size: 0.75rem; }
		.ebx-pricing-featured .ebx-plan-features li i { color: #d4a853; }
		.ebx-plan-btn {
			display: block; width: 100%; text-align: center;
			padding: 13px; border-radius: 10px; font-weight: 600;
			font-size: 0.92rem; transition: opacity 0.2s, transform 0.15s; cursor: pointer;
		}
		.ebx-plan-btn:hover { opacity: 0.88; transform: translateY(-1px); }
		.ebx-plan-btn-outline {
			background: transparent; border: 1px solid rgba(255,255,255,0.15);
			color: rgba(255,255,255,0.8);
		}
		.ebx-plan-btn-gold {
			background: #d4a853; color: #0a0700;
		}

		/* ── TOP-UP ── */
		.ebx-topup {
			max-width: 800px; margin: 0 auto; padding: 0;
			border: 1px solid rgba(255,255,255,0.08); border-radius: 16px;
			overflow: hidden;
		}
		.ebx-topup > h3 {
			font-size: 1.4rem; color: #fff; padding: 32px 32px 8px;
		}
		.ebx-topup > p {
			font-size: 0.85rem; color: rgba(255,255,255,0.45); padding: 0 32px 24px; line-height: 1.6;
		}
		.ebx-topup-grid {
			display: grid; grid-template-columns: repeat(3, 1fr);
			gap: 0;
		}
		.ebx-topup-card {
			background: #111113; border: 1px solid rgba(255,255,255,0.06);
			padding: 24px; text-align: center;
			transition: background 0.2s;
		}
		.ebx-topup-card:hover { background: #161618; }
		.ebx-topup-card strong { display: block; font-size: 1.4rem; color: #fff; font-family: "DM Sans", sans-serif; }
		.ebx-topup-card span { font-size: 0.75rem; color: rgba(255,255,255,0.35); }
		.ebx-topup-card p { font-size: 1rem; color: rgba(255,255,255,0.7); margin-top: 8px; font-weight: 600; }

		/* ── HOW IT WORKS ── */
		.ebx-how-it-works { max-width: 800px; margin: 64px auto 0; padding: 0 0; }
		.ebx-how-it-works .ebx-lp-eyebrow { text-align: center; margin-bottom: 32px; }
		.ebx-hiw-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 32px; }
		.ebx-hiw-card {
			background: #111113; border: 1px solid rgba(255,255,255,0.08);
			border-radius: 14px; padding: 28px; text-align: center;
		}
		.ebx-hiw-num { display: block; font-size: 0.75rem; color: rgba(255,255,255,0.3); margin-bottom: 14px; font-weight: 600; }
		.ebx-hiw-card h4 { font-size: 1rem; color: #fff; margin-bottom: 8px; }
		.ebx-hiw-card p { font-size: 0.82rem; color: rgba(255,255,255,0.45); line-height: 1.55; }
		.ebx-coin-notes {
			display: flex; flex-direction: column; gap: 6px;
			text-align: center; padding-top: 24px;
		}
		.ebx-coin-notes p { font-size: 0.8rem; color: rgba(255,255,255,0.3); }

		/* ── FINAL CTA ── */
		.ebx-lp-final-cta {
			padding: 120px 24px; text-align: center;
		}
		.ebx-lp-final-cta h2 {
			font-size: clamp(2.4rem, 6vw, 4.5rem); font-weight: 800;
			color: #fff; margin-bottom: 16px; line-height: 1.08;
		}
		.ebx-lp-final-cta p {
			font-size: 1rem; color: rgba(255,255,255,0.4);
			margin-bottom: 36px;
		}

		/* ── FOOTER ── */
		.ebx-lp-footer {
			border-top: 1px solid rgba(255,255,255,0.07);
			padding: 56px 24px 24px;
		}
		.ebx-lp-footer-inner {
			max-width: 1100px; margin: 0 auto;
			display: flex; gap: 64px; flex-wrap: wrap;
		}
		.ebx-footer-brand { max-width: 240px; }
		.ebx-footer-brand p { font-size: 0.83rem; color: rgba(255,255,255,0.35); line-height: 1.6; margin-top: 12px; }
		.ebx-footer-logo { margin-bottom: 4px; }
		.ebx-footer-links { display: flex; gap: 48px; flex: 1; flex-wrap: wrap; }
		.ebx-footer-col { display: flex; flex-direction: column; gap: 10px; }
		.ebx-footer-col h5 { font-size: 0.85rem; color: #fff; font-weight: 600; margin-bottom: 4px; }
		.ebx-footer-col a { font-size: 0.82rem; color: rgba(255,255,255,0.35); transition: color 0.2s; }
		.ebx-footer-col a:hover { color: rgba(255,255,255,0.8); }
		.ebx-footer-bottom {
			max-width: 1100px; margin: 40px auto 0;
			display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;
			padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05);
			font-size: 0.8rem; color: rgba(255,255,255,0.25);
		}

		/* ── RESPONSIVE ── */
		@media(max-width: 768px) {
			.ebx-lp-nav-links { display: none; flex-direction: column; position: absolute; top: 64px; left: 0; right: 0; background: #09090b; padding: 16px 24px; gap: 16px; border-bottom: 1px solid rgba(255,255,255,0.07); }
			.ebx-lp-nav-links.open { display: flex; }
			.ebx-lp-menu-toggle { display: block; }
			.ebx-lp-nav-cta .ebx-lp-login { display: none; }
			.ebx-lp-bento-grid { grid-template-columns: 1fr; }
			.ebx-bento-small-col { flex-direction: column; }
			.ebx-cap-grid { grid-template-columns: 1fr 1fr; }
			.ebx-why-grid { grid-template-columns: 1fr; }
			.ebx-community-grid { grid-template-columns: 1fr; }
			.ebx-pricing-grid { grid-template-columns: 1fr; }
			.ebx-topup-grid { grid-template-columns: repeat(2, 1fr); }
			.ebx-hiw-grid { grid-template-columns: 1fr; }
			.ebx-lp-footer-inner { flex-direction: column; gap: 32px; }
			.ebx-lp-section-header { flex-direction: column; }
		}
		@media(max-width: 480px) {
			.ebx-cap-grid { grid-template-columns: 1fr; }
			.ebx-lp-hero-ctas { flex-direction: column; align-items: center; }
			.ebx-topup-grid { grid-template-columns: 1fr 1fr; }
		}
		';
	}
}
