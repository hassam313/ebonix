<?php
if (!defined('QA_VERSION')) {
    header('Location: ../');
    exit;
}

require_once QA_INCLUDE_DIR . 'king-app/format.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';

$userid = qa_get_logged_in_userid();
$qa_content = qa_content_prepare();
$qa_content['title'] = 'AI Chat';
$king_ajax_url = rtrim((string)qa_opt('site_url'), '/') . '/king-include/king-ajax.php';
$site_url      = rtrim((string)qa_opt('site_url'), '/') . '/';

if (!qa_is_logged_in()) {
    $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-circle-user fa-4x"></i>'
        . qa_insert_login_links(qa_lang_html('question/ask_must_login'), qa_request())
        . '</div>';
    return $qa_content;
}

if (!qa_opt('enable_aichat')) {
    $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-comment-slash fa-4x"></i><p>AI Chat is not enabled.</p></div>';
    return $qa_content;
}

$coin_cost = (int)(qa_opt('coin_cost_chat') ?: 10);

$cont = '';

// ── Sub-navigation (includes AI Twin) ────────────────────────────────────────
if (qa_opt('king_leo_enable') || qa_opt('enable_aivideo') || qa_opt('enable_aitwin') || qa_opt('enable_aichat')) {
    $cont .= '<ul class="king-nav-kingsub-list" id="nav-kingsub">';
    if (qa_opt('king_leo_enable'))  $cont .= '<li class="king-nav-kingsub-item"><a href="'.qa_path_html('submitai').'"><i class="fa-regular fa-image"></i> '.qa_lang_html('misc/king_ai').'</a></li>';
    if (qa_opt('enable_aivideo'))   $cont .= '<li class="king-nav-kingsub-item"><a href="'.qa_path_html('videoai').'"><i class="fa-regular fa-circle-play"></i> '.qa_lang_html('misc/king_aivid').'</a></li>';
    if (qa_opt('enable_aitwin'))    $cont .= '<li class="king-nav-kingsub-item"><a href="'.qa_path_html('aitwin').'"><i class="fa-regular fa-user-circle"></i> AI Twin</a></li>';
    if (qa_opt('enable_aichat'))    $cont .= '<li class="king-nav-kingsub-item"><a href="'.qa_path_html('aichat').'" class="king-nav-kingsub-selected"><i class="fa-regular fa-comment-dots"></i> AI Chat</a></li>';
    $cont .= '</ul>';
}

// ── CSS ───────────────────────────────────────────────────────────────────────
$cont .= '<style>
/* ── Strip Q2A page shell — lock all scroll to .ebx-scroll only ── */
body.ai-create { overflow:hidden!important; }
.ai-create,
.ai-create #king-body-wrapper,
.ai-create .king-body-in,
.ai-create #container,
.ai-create .king-main,
.ai-create .king-main-in {
    padding:0!important; margin:0!important;
    width:100%!important; max-width:100%!important;
    box-sizing:border-box; overflow:hidden!important;
}
.ai-create .leo-nav { margin-bottom:0!important; }

/* ── Root container — full remaining height ── */
.ebx-root {
    display:flex;
    height:calc(100dvh - 185px);
    min-height:400px;
    width:100%;
    background:var(--ebx-bg,#fff);
    overflow:hidden;
    position:relative;
    border-top:1px solid var(--ebx-border,#e5e7eb);
}

/* ═══════════════════════════════════════════
   SIDEBAR  (ChatGPT / Claude left panel)
═══════════════════════════════════════════ */
.ebx-sidebar {
    width:256px; min-width:256px;
    background:var(--ebx-side,#f9f9fb);
    border-right:1px solid var(--ebx-border,#e5e7eb);
    display:flex; flex-direction:column;
    transition:width .22s cubic-bezier(.4,0,.2,1), min-width .22s;
    overflow:hidden; flex-shrink:0;
}
.ebx-sidebar.closed { width:0; min-width:0; }

.ebx-side-top {
    display:flex; align-items:center; justify-content:space-between;
    padding:14px 14px 10px; border-bottom:1px solid var(--ebx-border,#e5e7eb);
    flex-shrink:0; gap:8px; background:var(--ebx-side,#f9f9fb);
}
.ebx-side-label {
    font-size:11px; font-weight:700; letter-spacing:.5px;
    color:var(--ebx-text,#374151); white-space:nowrap;
}
.ebx-side-collapse {
    background:none; border:none; cursor:pointer;
    color:var(--ebx-muted,#9ca3af); width:28px; height:28px;
    border-radius:8px; display:flex; align-items:center; justify-content:center;
    font-size:13px; transition:background .15s,color .15s; flex-shrink:0;
}
.ebx-side-collapse:hover { background:var(--ebx-hover,#ede9f8); color:#7c3aed; }

.ebx-side-newchat {
    margin:10px 12px;
    display:flex; align-items:center; gap:8px;
    padding:9px 12px; background:none;
    border:1.5px dashed var(--ebx-border,#d1d5db);
    border-radius:10px; cursor:pointer;
    font-size:12.5px; font-weight:600;
    color:var(--ebx-muted2,#6b7280);
    transition:all .18s; width:calc(100% - 24px); white-space:nowrap;
}
.ebx-side-newchat:hover { border-color:#7c3aed; color:#7c3aed; background:rgba(124,58,237,.05); }

.ebx-side-history { flex:1; overflow-y:auto; padding:4px 8px 12px; }
.ebx-side-history::-webkit-scrollbar { width:3px; }
.ebx-side-history::-webkit-scrollbar-thumb { background:#ddd; border-radius:3px; }

.ebx-hist-empty { text-align:center; padding:20px 12px; color:var(--ebx-muted,#9ca3af); font-size:12px; }
.ebx-hist-item {
    display:flex; align-items:center; gap:6px;
    padding:9px 11px; border-radius:10px; cursor:pointer;
    font-size:12.5px; color:var(--ebx-text,#374151);
    transition:background .15s, border-color .15s; white-space:nowrap; overflow:hidden;
    background:var(--ebx-hist-bg,rgba(0,0,0,.03));
    border:1px solid var(--ebx-hist-border,rgba(0,0,0,.06));
    margin-bottom:4px;
}
.ebx-hist-item:hover { background:var(--ebx-hover,#ede9f8); border-color:rgba(124,58,237,.2); }
.ebx-hist-item.active { background:rgba(124,58,237,.12); border-color:rgba(124,58,237,.3); color:#6d28d9; font-weight:600; }
.ebx-hist-title { flex:1; overflow:hidden; text-overflow:ellipsis; }
.ebx-hist-del, .ebx-hist-ren {
    background:none; border:none; cursor:pointer;
    padding:2px 4px; border-radius:4px; opacity:0; font-size:10px; flex-shrink:0;
}
.ebx-hist-del { color:#c4c4d4; }
.ebx-hist-ren { color:#c4c4d4; }
.ebx-hist-item:hover .ebx-hist-del,
.ebx-hist-item:hover .ebx-hist-ren { opacity:1; }
.ebx-hist-del:hover { color:#ef4444; }
.ebx-hist-ren:hover { color:#7c3aed; }

/* Inline rename input */
.ebx-hist-rename {
    flex:1; border:none; background:transparent; outline:none;
    font-size:12.5px; color:var(--ebx-text,#374151);
    font-family:inherit; padding:0; min-width:0;
}
.king-lnight .ebx-hist-rename { color:#e2e8f0; }

/* ═══════════════════════════════════════════
   MAIN CHAT AREA
═══════════════════════════════════════════ */
.ebx-main {
    flex:1; display:flex; flex-direction:column; overflow:hidden;
    background:var(--ebx-bg,#fff); position:relative;
}

/* Top bar — mode selector */
.ebx-modebar {
    display:flex; align-items:center; gap:6px;
    padding:10px 16px; border-bottom:1px solid var(--ebx-border,#e5e7eb);
    overflow-x:auto; flex-shrink:0; scrollbar-width:none;
    background:var(--ebx-bg,#fff);
}
.ebx-modebar::-webkit-scrollbar { display:none; }
.ebx-mode {
    display:flex; align-items:center; gap:8px;
    padding:7px 13px; border:1.5px solid var(--ebx-border,#e2e5eb);
    border-radius:12px; background:transparent; cursor:pointer;
    white-space:nowrap; transition:all .18s; flex-shrink:0;
}
.ebx-mode:hover { border-color:#7c3aed; background:rgba(124,58,237,.04); }
.ebx-mode.active { border-color:#7c3aed; background:rgba(124,58,237,.09); color:#6d28d9; }
.ebx-mode-icon { font-size:17px; line-height:1; }
.ebx-mode-text strong { display:block; font-size:12.5px; font-weight:700; line-height:1.25; }
.ebx-mode-text small  { display:block; font-size:10.5px; color:var(--ebx-muted,#9ca3af); }
.ebx-mode.active .ebx-mode-text small { color:rgba(109,40,217,.6); }

.ebx-bar-right { margin-left:auto; display:flex; gap:6px; flex-shrink:0; align-items:center; }
.ebx-bar-btn {
    padding:7px 12px; background:none; border:1.5px solid var(--ebx-border,#e2e5eb);
    border-radius:10px; cursor:pointer; font-size:12px; font-weight:600;
    color:var(--ebx-muted2,#6b7280); display:flex; align-items:center; gap:5px;
    transition:all .18s; white-space:nowrap;
}
.ebx-bar-btn:hover { border-color:#7c3aed; color:#7c3aed; background:rgba(124,58,237,.05); }
.ebx-bar-btn.icon-only { padding:7px 9px; }

/* ── Messages scroll area ── */
.ebx-scroll {
    flex:1; overflow-y:auto; display:flex; flex-direction:column;
    scroll-behavior:smooth;
}
.ebx-scroll::-webkit-scrollbar { width:5px; }
.ebx-scroll::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:5px; }

/* ── EMPTY STATE: centred like ChatGPT/Claude ── */
.ebx-empty {
    flex:1; display:flex; flex-direction:column;
    align-items:center; justify-content:center;
    text-align:center; padding:32px 20px 16px; min-height:0;
}
.ebx-empty-logo {
    width:56px; height:56px; border-radius:18px;
    background:linear-gradient(135deg,#6d28d9,#a855f7);
    display:flex; align-items:center; justify-content:center;
    font-size:24px; color:#fff; margin-bottom:14px;
    box-shadow:0 8px 24px rgba(109,40,217,.28);
}
.ebx-empty-title {
    font-size:26px; font-weight:800;
    color:var(--ebx-text,#111827); margin:0 0 8px; line-height:1.2;
}
.ebx-empty-sub {
    font-size:14px; color:var(--ebx-muted,#9ca3af);
    max-width:360px; margin:0 0 20px; line-height:1.55;
}
.ebx-chips {
    display:flex; flex-wrap:wrap; gap:8px;
    justify-content:center; max-width:580px;
}
.ebx-chip {
    padding:8px 14px; background:var(--ebx-side,#f9f9fb);
    border:1.5px solid var(--ebx-border,#e2e5eb);
    border-radius:20px; font-size:12.5px; font-weight:500;
    color:var(--ebx-text2,#4b5563); cursor:pointer;
    transition:all .18s; display:flex; align-items:center; gap:5px;
}
.ebx-chip:hover { border-color:#7c3aed; color:#7c3aed; background:rgba(124,58,237,.06); transform:translateY(-1px); }

/* ── Message rows ── */
@keyframes ebxIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }

.ebx-msg-row {
    padding:8px 24px;
    display:flex;
    animation:ebxIn .22s ease;
}

/* AI row: flush left */
.ebx-msg-row.ai-row  { justify-content:flex-start; }
/* User row: flush right */
.ebx-msg-row.user-row { justify-content:flex-end; }

/* Inner content wrapper */
.ebx-msg-inner {
    display:flex; gap:12px; align-items:flex-start;
    max-width:75%;
}

/* AI inner: avatar + text on left */
.ebx-msg-row.ai-row .ebx-msg-inner  { flex-direction:row; }
/* User inner: bubble on right, no avatar */
.ebx-msg-row.user-row .ebx-msg-inner { flex-direction:row; }
.ebx-msg-row.user-row .ebx-avatar   { display:none; }

.ebx-avatar {
    width:32px; height:32px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:11px; font-weight:800; color:#fff;
    letter-spacing:.3px; margin-top:3px;
}
.ebx-avatar.ai  { background:linear-gradient(135deg,#6d28d9,#a855f7); box-shadow:0 2px 8px rgba(109,40,217,.22); }

/* Bubble base */
.ebx-bubble {
    font-size:14px; line-height:1.75;
    color:var(--ebx-text,#1f2937); word-break:break-word;
    text-align:left;
}
/* AI: no background, just text */
.ebx-msg-row.ai-row .ebx-bubble {
    background:none; padding:2px 0;
}
/* User: purple pill, right side */
.ebx-msg-row.user-row .ebx-bubble {
    background:linear-gradient(135deg,#6d28d9,#a855f7);
    color:#fff; padding:11px 16px;
    border-radius:20px 20px 4px 20px;
}

.ebx-bubble pre { background:rgba(0,0,0,.07); border-radius:8px; padding:10px 12px; overflow-x:auto; font-size:12.5px; margin:8px 0; white-space:pre-wrap; }
.user-row .ebx-bubble pre { background:rgba(255,255,255,.15); }
.ebx-bubble code { background:rgba(0,0,0,.07); border-radius:4px; padding:1px 5px; font-size:12.5px; font-family:monospace; }
.user-row .ebx-bubble code { background:rgba(255,255,255,.2); }
.ebx-bubble ul,.ebx-bubble ol { padding-left:20px; margin:6px 0; }
.ebx-bubble strong { font-weight:700; }
.ebx-bubble em { font-style:italic; }
.ebx-bubble h3,.ebx-bubble h4 { font-weight:700; margin:8px 0 4px; }

/* Typing — matches ai-row: flush left, avatar + dots */
.ebx-typing-row { padding:8px 24px; display:flex; justify-content:flex-start; }
.ebx-typing-inner { display:flex; gap:12px; align-items:center; }
.ebx-dots { padding:10px 14px; background:var(--ebx-input-bg,#f4f4f8); border-radius:12px; display:flex; gap:5px; align-items:center; }
.ebx-dot { width:7px; height:7px; border-radius:50%; background:#7c3aed; animation:ebxBounce 1.3s ease infinite; opacity:.5; }
.ebx-dot:nth-child(2){animation-delay:.18s}
.ebx-dot:nth-child(3){animation-delay:.36s}
@keyframes ebxBounce { 0%,60%,100%{transform:scale(1);opacity:.45} 30%{transform:scale(1.4);opacity:1} }

/* ═══════════════════════════════════════════
   INPUT AREA — always at the bottom
═══════════════════════════════════════════ */
.ebx-input-area {
    flex-shrink:0;
    position:relative;
    padding:12px 20px 16px;
    border-top:1px solid var(--ebx-border,#e5e7eb);
    background:var(--ebx-bg,#fff);
    z-index:10;
}
/* Centre input to 760px like ChatGPT */
.ebx-input-centre { max-width:760px; margin:0 auto; }

.ebx-input-box {
    display:flex; align-items:center; gap:10px;
    background:var(--ebx-input-bg,#f4f4f8);
    border:1.5px solid transparent;
    border-radius:14px; padding:0 10px 0 16px;
    min-height:52px;
    transition:border-color .18s, background .18s;
}
.ebx-input-box:focus-within {
    border-color:#7c3aed;
    background:var(--ebx-bg,#fff);
}
.ebx-textarea {
    flex:1; border:none; background:transparent;
    outline:none !important; box-shadow:none !important;
    -webkit-appearance:none; appearance:none;
    resize:none; font-size:14.5px; line-height:1.6;
    color:var(--ebx-text,#111827);
    max-height:160px; min-height:24px;
    overflow-y:auto; font-family:inherit;
    padding:14px 0; display:block;
}
.ebx-textarea::placeholder { color:var(--ebx-muted,#a0a3b1); }
.ebx-textarea::-webkit-scrollbar { width:3px; }

.ebx-input-btns { display:flex; gap:6px; align-items:center; flex-shrink:0; }

.ebx-enhance {
    height:36px; padding:0 13px;
    border:1.5px solid var(--ebx-border,#e2e5eb);
    border-radius:10px; background:transparent;
    cursor:pointer; font-size:12px; font-weight:600;
    color:var(--ebx-muted2,#6b7280);
    display:flex; align-items:center; gap:5px;
    transition:all .18s; white-space:nowrap;
}
.ebx-enhance:hover { border-color:#f59e0b; color:#b45309; background:#fef9ee; }
.ebx-enhance.loading { opacity:.5; pointer-events:none; }

.ebx-send-btn {
    width:38px; height:38px; border-radius:10px;
    background:linear-gradient(135deg,#6d28d9,#a855f7);
    color:#fff; border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    font-size:15px; transition:opacity .18s, transform .15s; flex-shrink:0;
}
.ebx-send-btn:hover { opacity:.88; transform:scale(1.05); }
.ebx-send-btn:disabled { opacity:.35; cursor:not-allowed; transform:none; }

/* Addon chips row */
.ebx-addons {
    display:flex; gap:6px; margin-top:8px;
    flex-wrap:wrap; align-items:center;
}
.ebx-addon {
    padding:4px 10px; border-radius:8px;
    border:1px solid var(--ebx-border,#e2e5eb);
    background:transparent; font-size:11px; font-weight:600;
    color:var(--ebx-muted2,#6b7280); cursor:pointer;
    display:flex; align-items:center; gap:4px;
    transition:all .15s; white-space:nowrap;
}
.ebx-addon:hover { border-color:#7c3aed; color:#7c3aed; background:rgba(124,58,237,.05); }
.ebx-addon.on { border-color:#7c3aed; color:#6d28d9; background:rgba(124,58,237,.1); font-weight:700; }
.ebx-coins {
    margin-left:auto; font-size:11px; color:var(--ebx-muted,#9ca3af);
    display:flex; align-items:center; gap:4px; flex-shrink:0;
}
.ebx-coins i { color:#f59e0b; }

/* ═══════════════════════════════════════════
   DARK MODE
═══════════════════════════════════════════ */
.king-lnight {
    --ebx-bg:#16162a; --ebx-side:#111127; --ebx-border:#2a2a44;
    --ebx-text:#e2e8f0; --ebx-text2:#cbd5e1; --ebx-muted:#8b8fa8; --ebx-muted2:#94a3b8;
    --ebx-hover:rgba(124,58,237,.18); --ebx-bubble-ai:#1e1e38;
    --ebx-hist-bg:rgba(255,255,255,.04); --ebx-hist-border:rgba(255,255,255,.07);
    --ebx-input-bg:#1e1e38;
}
.king-lnight .ebx-empty-title { color:#f1f5f9; }
.king-lnight .ebx-chip { background:#1e1e38; border-color:#2a2a44; color:#cbd5e1; }
.king-lnight .ebx-chip:hover { background:rgba(124,58,237,.18); color:#c4b5fd; }
/* Mode bar dark mode — make subtitles readable */
.king-lnight .ebx-mode { background:#16162a; border-color:#2a2a44; color:#e2e8f0; }
.king-lnight .ebx-mode .ebx-mode-text strong { color:#e2e8f0; }
.king-lnight .ebx-mode .ebx-mode-text small { color:#7878a0; }
.king-lnight .ebx-mode.active { background:rgba(124,58,237,.22); border-color:rgba(124,58,237,.5); color:#c4b5fd; }
.king-lnight .ebx-mode.active .ebx-mode-text strong { color:#e0d0ff; }
.king-lnight .ebx-mode.active .ebx-mode-text small { color:#a78bfa; }
/* History items dark mode */
.king-lnight .ebx-hist-item { color:#9da5bf; }
.king-lnight .ebx-hist-item:hover { color:#e2e8f0; }
.king-lnight .ebx-hist-item.active { color:#c4b5fd; }
/* AI bubble dark mode */
.king-lnight .ebx-msg-row.ai-row .ebx-bubble { color:#e2e8f0; }
.king-lnight .ebx-input-box { border-color:transparent; }
.king-lnight .ebx-input-box:focus-within { background:#16162a; border-color:#7c3aed; }
.king-lnight .ebx-textarea { color:#e2e8f0; }
.king-lnight .ebx-enhance { background:transparent; border-color:#2a2a44; color:#94a3b8; }

/* ═══════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════ */
/* Tablet */
@media (max-width:900px) {
    .ebx-root { height:calc(100dvh - 108px); }
    .ebx-sidebar { width:220px; min-width:220px; }
    .ebx-mode-text small { display:none; }
}
/* Mobile */
@media (max-width:640px) {
    .ebx-root { height:calc(100dvh - 96px); }
    /* Sidebar becomes drawer */
    .ebx-sidebar {
        position:absolute; top:0; left:0; bottom:0; z-index:40;
        box-shadow:8px 0 32px rgba(0,0,0,.25);
        background:var(--ebx-side,#f9f9fb);
        border-radius:0 16px 16px 0;
        width:260px; min-width:260px;
        overflow:hidden;
    }
    .ebx-sidebar.closed { width:0; min-width:0; box-shadow:none; overflow:hidden; }
    .ebx-overlay {
        display:none; position:absolute; inset:0;
        background:rgba(0,0,0,.4); z-index:35;
    }
    .ebx-overlay.show { display:block; }
    .ebx-modebar { padding:8px 12px; gap:5px; }
    .ebx-mode-text strong { font-size:11.5px; }
    .ebx-mode { padding:6px 8px; gap:6px; }
    .ebx-mode-icon { font-size:15px; }
    .ebx-empty-title { font-size:22px; }
    .ebx-empty-sub { font-size:13px; }
    .ebx-bubble { font-size:13.5px; }
    .ebx-msg-row { padding:4px 12px; }
    .ebx-typing-row { padding:4px 12px; }
    .ebx-input-area { padding:10px 12px 14px; }
    .ebx-enhance span { display:none; }
    .ebx-enhance { padding:0 9px; }
    .ebx-addon { font-size:10.5px; padding:3px 8px; }
    .ebx-chip { font-size:11.5px; padding:6px 11px; }
}
@media (max-width:400px) {
    .ebx-root { height:calc(100dvh - 88px); }
    .ebx-empty-title { font-size:20px; }
    .ebx-addon { display:none; }
    .ebx-addons .ebx-coins { margin-left:0; }
}
</style>';

// ── HTML Structure ────────────────────────────────────────────────────────────
$cont .= '<div class="ebx-root">';
$cont .= '<div class="ebx-overlay" id="ebx-overlay"></div>';

// ── Sidebar ───────────────────────────────────────────────────────────────────
$cont .= '<div class="ebx-sidebar" id="ebx-sidebar">';
$cont .= '<div class="ebx-side-top">';
$cont .= '<span class="ebx-side-label"><i class="fa-regular fa-comment-dots" style="margin-right:6px;color:#7c3aed"></i>Chats</span>';
$cont .= '<button class="ebx-side-collapse" id="ebx-collapse" title="Collapse sidebar"><i class="fa-solid fa-chevron-left" id="ebx-collapse-icon"></i></button>';
$cont .= '</div>';
$cont .= '<button class="ebx-side-newchat" id="ebx-side-new"><i class="fa-solid fa-plus"></i> New chat</button>';
$cont .= '<div class="ebx-side-history" id="ebx-history"><div class="ebx-hist-empty">No chats yet</div></div>';
$cont .= '</div>';

// ── Main area ─────────────────────────────────────────────────────────────────
$cont .= '<div class="ebx-main">';

// Mode bar
$cont .= '<div class="ebx-modebar">';
$cont .= '<button class="ebx-bar-btn icon-only" id="ebx-open-sidebar" style="display:none"><i class="fa-solid fa-bars"></i></button>';
$cont .= '<button class="ebx-mode active" data-mode="aave">';
$cont .= '<span class="ebx-mode-icon">✊🏿</span><div class="ebx-mode-text"><strong>AAVE</strong><small>African American Vernacular English</small></div>';
$cont .= '</button>';
$cont .= '<button class="ebx-mode" data-mode="deep_vibe">';
$cont .= '<span class="ebx-mode-icon"><i class="fa-solid fa-flask"></i></span><div class="ebx-mode-text"><strong>Deep Vibe</strong><small>Research, analysis &amp; coding</small></div>';
$cont .= '</button>';
$cont .= '<button class="ebx-mode" data-mode="code_switch">';
$cont .= '<span class="ebx-mode-icon"><i class="fa-solid fa-briefcase"></i></span><div class="ebx-mode-text"><strong>Code Switch</strong><small>Professional &amp; business</small></div>';
$cont .= '</button>';
$cont .= '<div class="ebx-bar-right">';
$cont .= '<button class="ebx-bar-btn" id="ebx-bar-new"><i class="fa-solid fa-plus"></i> New chat</button>';
$cont .= '</div>';
$cont .= '</div>'; // modebar

// Scrollable messages area
$cont .= '<div class="ebx-scroll" id="ebx-scroll">';

// Empty state (visible by default, hidden once messages arrive)
$cont .= '<div class="ebx-empty" id="ebx-empty">';
$cont .= '<div class="ebx-empty-logo">✊🏿</div>';
$cont .= '<h2 class="ebx-empty-title">Say Somethin\'</h2>';
$cont .= '<p class="ebx-empty-sub" id="ebx-empty-sub">African American Vernacular English — speak your truth and I\'ll meet you there.</p>';
$cont .= '<div class="ebx-chips" id="ebx-chips">';
$chips = [
    ['✨','Tell me about Black excellence'],
    ['💼','Black-owned business ideas'],
    ['📚','Black history fact'],
    ['💇🏿','Natural hair tips'],
    ['🎵','Influential Black artists'],
    ['🏫','HBCU pros & cons'],
    ['💪🏿','Give me an affirmation'],
    ['🚀','Career advice for Black professionals'],
];
foreach ($chips as $c) {
    $cont .= '<button class="ebx-chip" data-p="'.qa_html($c[1]).'">'.$c[0].' '.qa_html($c[1]).'</button>';
}
$cont .= '</div>'; // chips
$cont .= '</div>'; // empty

$cont .= '</div>'; // scroll

// Input area — always at the bottom
$cont .= '<div class="ebx-input-area">';
$cont .= '<div class="ebx-input-centre">';
$cont .= '<div class="ebx-input-box">';
$cont .= '<textarea id="ebx-ta" class="ebx-textarea" placeholder="Ask anything..." rows="1" maxlength="4000"></textarea>';
$cont .= '<div class="ebx-input-btns">';
$cont .= '<button class="ebx-enhance" id="ebx-enhance" title="Enhance prompt with AI"><i class="fa-solid fa-wand-magic-sparkles"></i><span> Enhance</span></button>';
$cont .= '<button class="ebx-send-btn" id="ebx-send"><i class="fa-solid fa-paper-plane"></i></button>';
$cont .= '</div>';
$cont .= '</div>'; // input-box
$cont .= '<div class="ebx-addons">';
$addons = [
    ['affirmation','🙏🏿','Affirmation'],
    ['real','🧠','Keep it real'],
    ['biz','💰','Business lens'],
    ['short','⚡','Short answer'],
];
foreach ($addons as $a) {
    $cont .= '<button class="ebx-addon" data-a="'.qa_html($a[0]).'">'.$a[1].' '.qa_html($a[2]).'</button>';
}
$cont .= '<span class="ebx-coins"><i class="fa-solid fa-coins"></i>'.$coin_cost.' coins/msg</span>';
$cont .= '</div>'; // addons
$cont .= '</div>'; // input-centre
$cont .= '</div>'; // input-area

$cont .= '</div>'; // main
$cont .= '</div>'; // root

$qa_content['custom'] = $cont;

// ── JavaScript ────────────────────────────────────────────────────────────────
$qa_content['custom'] .= '<script>
(function(){
"use strict";
var AJAX  = '.json_encode($king_ajax_url).';
var ROOT  = '.json_encode($site_url).';
var COST  = '.$coin_cost.';
var session = null, mode = "aave", busy = false, addons = {};

var modeDesc = {
    aave:        "African American Vernacular English — speak your truth and I\'ll meet you there.",
    deep_vibe:   "Research, analysis & coding — ask anything and I\'ll deliver.",
    code_switch: "Professional, education & business — let\'s get it done."
};

/* ── AJAX ── */
function api(data, cb){
    var fd = new FormData();
    fd.append("qa_operation","aichat");
    fd.append("qa_request","aichat");
    fd.append("qa_root", ROOT);
    Object.keys(data).forEach(function(k){ fd.append(k,data[k]); });
    var x = new XMLHttpRequest();
    x.open("POST",AJAX,true); x.timeout=120000;
    x.onload=function(){
        var t=(x.responseText||"").trim();
        if(t.indexOf("QA_AJAX_RESPONSE\n")===0) t=t.slice(17);
        var lines=t.split("\n"), ok=lines[0]==="1", d=null;
        try{d=JSON.parse(lines[1]);}catch(e){}
        cb(ok,d);
    };
    x.onerror=x.ontimeout=function(){ cb(false,{message:"Connection error"}); };
    x.send(fd);
}

/* ── Helpers ── */
function esc(s){ return s.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"); }

function fmt(text){
    text=text.replace(/```(\w*)\n?([\s\S]*?)```/g,function(_,l,c){ return "<pre><code>"+esc(c.trim())+"</code></pre>"; });
    text=text.replace(/`([^`\n]+)`/g,"<code>$1</code>");
    text=text.replace(/\*\*(.+?)\*\*/g,"<strong>$1</strong>");
    text=text.replace(/\*(.+?)\*/g,"<em>$1</em>");
    text=text.replace(/^#{1,3} (.+)$/gm,"<strong>$1</strong>");
    text=text.replace(/^[-*] (.+)$/gm,"• $1");
    text=text.replace(/\n\n/g,"<br><br>").replace(/\n/g,"<br>");
    return text;
}

function scroll(){ var el=document.getElementById("ebx-scroll"); if(el) el.scrollTop=el.scrollHeight; }

/* ── Append a message bubble ── */
function addMsg(role, text){
    // Hide empty state on first message
    var em=document.getElementById("ebx-empty");
    if(em) em.style.display="none";

    var sc=document.getElementById("ebx-scroll");
    var row=document.createElement("div");
    row.className="ebx-msg-row "+(role==="user"?"user-row":"ai-row");
    var avCls=role==="user"?"you":"ai";
    var avLbl=role==="user"?"You":"AI";
    row.innerHTML=
        "<div class=\"ebx-msg-inner\">"+
        "<div class=\"ebx-avatar "+avCls+"\">"+avLbl+"</div>"+
        "<div class=\"ebx-bubble\">"+(role==="user"?esc(text):fmt(text))+"</div>"+
        "</div>";
    sc.appendChild(row);
    scroll();
}

/* ── Typing indicator ── */
function showTyping(){
    var sc=document.getElementById("ebx-scroll");
    var d=document.createElement("div");
    d.className="ebx-typing-row"; d.id="ebx-typing";
    d.innerHTML="<div class=\"ebx-typing-inner\"><div class=\"ebx-avatar ai\">AI</div><div class=\"ebx-dots\"><div class=\"ebx-dot\"></div><div class=\"ebx-dot\"></div><div class=\"ebx-dot\"></div></div></div>";
    sc.appendChild(d); scroll();
}
function hideTyping(){ var d=document.getElementById("ebx-typing"); if(d) d.remove(); }

/* ── Session persistence ── */
function saveSession(id){
    try{ localStorage.setItem("ebx_session",""+( id||"")); }catch(e){}
}
function getSavedSession(){
    try{ return localStorage.getItem("ebx_session")||""; }catch(e){ return ""; }
}

/* ── Send ── */
function send(override){
    if(busy) return;
    var ta=document.getElementById("ebx-ta");
    var msg=override||ta.value.trim();
    if(!msg) return;

    var suffix="";
    if(addons.affirmation) suffix+=" [End your response with a powerful Black empowerment affirmation.]";
    if(addons.real)        suffix+=" [Be completely real — no sugarcoating.]";
    if(addons.biz)         suffix+=" [Include a Black entrepreneurship or business perspective.]";
    if(addons.short)       suffix+=" [Keep your answer under 4 sentences.]";

    ta.value=""; ta.style.height="auto";
    addMsg("user",msg);
    showTyping();
    busy=true;
    document.getElementById("ebx-send").disabled=true;

    api({chat_action:"send",message:msg+suffix,mode:mode,session_id:session||""},function(ok,d){
        hideTyping(); busy=false;
        document.getElementById("ebx-send").disabled=false;
        if(!d){ addMsg("assistant","Something went wrong. Try again."); return; }
        if(ok&&d.success){
            session=d.session_id;
            saveSession(session);
            addMsg("assistant",d.reply);
            if(typeof ebonixRefreshCoinDisplay==="function"&&d.coins_remaining!==undefined)
                ebonixRefreshCoinDisplay(d.coins_remaining);
            // Reload history — if AI generated a new title it will appear
            loadHistory();
        } else {
            var err=d.message||"Error";
            if(err==="insufficient_coins"&&typeof ebonixHandleCoinError==="function")
                ebonixHandleCoinError(err,d);
            else addMsg("assistant","⚠️ "+esc(err));
        }
    });
}

/* ── Enhance prompt (free) ── */
function enhance(){
    var ta=document.getElementById("ebx-ta");
    var msg=ta.value.trim();
    if(!msg) return;
    var btn=document.getElementById("ebx-enhance");
    btn.classList.add("loading");
    api({chat_action:"enhance",message:msg},function(ok,d){
        btn.classList.remove("loading");
        if(ok&&d&&d.success&&d.reply){
            ta.value=d.reply.trim();
            ta.style.height="auto";
            ta.style.height=Math.min(ta.scrollHeight,160)+"px";
        }
    });
}

/* ── History ── */
function loadHistory(){
    api({chat_action:"get_sessions"},function(ok,d){
        if(!ok||!d||!d.sessions) return;
        var list=document.getElementById("ebx-history");
        list.innerHTML="";
        if(!d.sessions.length){
            list.innerHTML="<div class=\"ebx-hist-empty\">No chats yet</div>"; return;
        }
        d.sessions.forEach(function(s){
            var el=document.createElement("div");
            el.className="ebx-hist-item"+(s.id===session?" active":"");
            var t=(s.title||"Chat").slice(0,50);
            el.innerHTML="<span class=\"ebx-hist-title\">"+esc(t)+"</span>"
                +"<button class=\"ebx-hist-ren\" data-id=\""+s.id+"\" title=\"Rename\"><i class=\"fa-solid fa-pen\"></i></button>"
                +"<button class=\"ebx-hist-del\" data-id=\""+s.id+"\" title=\"Delete\"><i class=\"fa-solid fa-xmark\"></i></button>";
            el.addEventListener("click",function(e){
                if(e.target.closest(".ebx-hist-del")||e.target.closest(".ebx-hist-ren")) return;
                openSession(s.id);
            });
            el.querySelector(".ebx-hist-del").addEventListener("click",function(e){
                e.stopPropagation(); delSession(s.id);
            });
            el.querySelector(".ebx-hist-ren").addEventListener("click",function(e){
                e.stopPropagation(); startRename(el, s.id, s.title||"Chat");
            });
            list.appendChild(el);
        });
    });
}

function openSession(id){
    api({chat_action:"get_messages",session_id:id},function(ok,d){
        if(!ok||!d||!d.session) return;
        session=id;
        saveSession(id);
        setMode(d.session.mode||"aave",false);
        clearMessages();
        (d.messages||[]).forEach(function(m){ addMsg(m.role,m.message); });
        loadHistory();
    });
}

function delSession(id){
    api({chat_action:"delete_session",session_id:id},function(){
        if(id===session) newChat();
        loadHistory();
    });
}

function newChat(){
    session=null;
    saveSession("");
    clearMessages();
    document.querySelectorAll(".ebx-hist-item").forEach(function(e){ e.classList.remove("active"); });
    document.getElementById("ebx-ta").focus();
}

function clearMessages(){
    var sc=document.getElementById("ebx-scroll");
    // Remove all message rows, keep empty state
    sc.querySelectorAll(".ebx-msg-row,.ebx-typing-row").forEach(function(e){ e.remove(); });
    var em=document.getElementById("ebx-empty");
    if(!em){
        // Re-create empty state
        em=document.createElement("div"); em.className="ebx-empty"; em.id="ebx-empty";
        em.innerHTML="<div class=\"ebx-empty-logo\">✊🏿</div>"
            +"<h2 class=\"ebx-empty-title\">Say Somethin\'</h2>"
            +"<p class=\"ebx-empty-sub\" id=\"ebx-empty-sub\">"+esc(modeDesc[mode]||"")+"</p>"
            +"<div class=\"ebx-chips\" id=\"ebx-chips\"></div>";
        sc.insertBefore(em,sc.firstChild);
        buildChips();
    } else {
        em.style.display="";
        var sub=document.getElementById("ebx-empty-sub");
        if(sub) sub.textContent=modeDesc[mode]||"";
    }
}

function buildChips(){
    var c=document.getElementById("ebx-chips"); if(!c) return;
    var list=[
        ["✨","Tell me about Black excellence"],
        ["💼","Black-owned business ideas"],
        ["📚","Black history fact"],
        ["💇🏿","Natural hair tips for 4C hair"],
        ["🎵","Most influential Black musicians ever"],
        ["🏫","HBCU vs PWI — which is right for me?"],
        ["💪🏿","Give me a powerful Black affirmation"],
        ["🚀","Career advice for Black professionals"],
    ];
    list.forEach(function(item){
        var btn=document.createElement("button"); btn.className="ebx-chip";
        btn.innerHTML=item[0]+" "+esc(item[1]);
        btn.addEventListener("click",function(){ send(item[1]); });
        c.appendChild(btn);
    });
}

/* ── Inline rename ── */
function startRename(el, id, currentTitle){
    var titleSpan = el.querySelector(".ebx-hist-title");
    var renBtn    = el.querySelector(".ebx-hist-ren");
    var delBtn    = el.querySelector(".ebx-hist-del");
    var inp = document.createElement("input");
    inp.className = "ebx-hist-rename";
    inp.value = currentTitle;
    titleSpan.replaceWith(inp);
    if(renBtn) renBtn.style.display="none";
    if(delBtn) delBtn.style.display="none";
    inp.focus(); inp.select();

    function commit(){
        var newTitle = inp.value.trim() || currentTitle;
        api({chat_action:"rename_session", session_id:id, title:newTitle}, function(){
            loadHistory();
        });
    }
    inp.addEventListener("keydown",function(e){
        if(e.key==="Enter"){ e.preventDefault(); commit(); }
        if(e.key==="Escape"){ loadHistory(); }
    });
    inp.addEventListener("blur", commit);
}

/* ── Set mode ── */
function setMode(m,reset){
    mode=m;
    document.querySelectorAll(".ebx-mode").forEach(function(b){
        b.classList.toggle("active",b.dataset.mode===m);
    });
    var sub=document.getElementById("ebx-empty-sub");
    if(sub) sub.textContent=modeDesc[m]||"";
    if(reset!==false) newChat();
}

/* ── Sidebar ── */
function toggleSidebar(){
    var sb=document.getElementById("ebx-sidebar");
    var ic=document.getElementById("ebx-collapse-icon");
    var ob=document.getElementById("ebx-open-sidebar");
    var ov=document.getElementById("ebx-overlay");
    var closed=sb.classList.toggle("closed");
    if(ic) ic.className=closed?"fa-solid fa-bars":"fa-solid fa-chevron-left";
    if(ob) ob.style.display=closed?"flex":"none";
    if(ov&&window.innerWidth<=640) ov.classList.toggle("show",!closed);
}

/* ── Init ── */
document.addEventListener("DOMContentLoaded",function(){
    // Mode buttons
    document.querySelectorAll(".ebx-mode").forEach(function(b){
        b.addEventListener("click",function(){ setMode(b.dataset.mode,true); });
    });
    // New chat
    ["ebx-side-new","ebx-bar-new"].forEach(function(id){
        var el=document.getElementById(id);
        if(el) el.addEventListener("click",newChat);
    });
    // Send & enhance
    document.getElementById("ebx-send").addEventListener("click",function(){ send(); });
    document.getElementById("ebx-enhance").addEventListener("click",enhance);
    // Textarea
    var ta=document.getElementById("ebx-ta");
    ta.addEventListener("keydown",function(e){
        if(e.key==="Enter"&&!e.shiftKey){ e.preventDefault(); send(); }
    });
    ta.addEventListener("input",function(){
        this.style.height="auto";
        this.style.height=Math.min(this.scrollHeight,160)+"px";
    });
    // Sidebar
    document.getElementById("ebx-collapse").addEventListener("click",toggleSidebar);
    document.getElementById("ebx-open-sidebar").addEventListener("click",toggleSidebar);
    document.getElementById("ebx-overlay").addEventListener("click",toggleSidebar);
    // Addons
    document.querySelectorAll(".ebx-addon").forEach(function(btn){
        btn.addEventListener("click",function(){
            var id=btn.dataset.a;
            addons[id]=!addons[id];
            btn.classList.toggle("on",!!addons[id]);
        });
    });
    // Chips (already in HTML — wire them up)
    document.querySelectorAll(".ebx-chip[data-p]").forEach(function(c){
        c.addEventListener("click",function(){ send(c.dataset.p); });
    });
    // Auto-collapse sidebar on mobile/tablet
    if(window.innerWidth<=900){
        var sb=document.getElementById("ebx-sidebar");
        if(sb) sb.classList.add("closed");
        var ob=document.getElementById("ebx-open-sidebar");
        if(ob) ob.style.display="flex";
    }
    // Restore last active session from localStorage
    var saved=getSavedSession();
    if(saved){
        openSession(saved);
    } else {
        loadHistory();
    }
});
})();
</script>';

$qa_content['class'] = ' ai-create';
return $qa_content;
