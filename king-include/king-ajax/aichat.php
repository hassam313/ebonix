<?php
if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

header('Content-Type: application/json');
set_time_limit(120);
ini_set('memory_limit', '256M');
ini_set('display_errors', '0');
error_reporting(0);

// Buffer ALL output — Q2A's qa_db_fail_error() echoes then exits,
// which would corrupt our JSON. Shutdown function catches that exit
// and swaps the raw error for a clean JSON response.
ob_start();
register_shutdown_function(function () {
    $out = ob_get_clean();
    // If our proper AJAX response was already sent, just echo it
    if (strpos($out, 'QA_AJAX_RESPONSE') === 0) {
        echo $out;
        return;
    }
    // Otherwise something crashed (DB fail, etc.) — return clean JSON error
    header('Content-Type: application/json');
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
        'success' => false,
        'message' => 'Something went wrong. Please try again.',
    ]) . "\n";
});

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';

$userid = qa_get_logged_in_userid();

if (!$userid) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Login required']) . "\n";
    exit;
}

ebonix_ensure_initialized($userid);

$action = qa_post_text('chat_action') ?: 'send';

// ── Force utf8mb4 on this connection so emoji saves correctly ───────────────
qa_db_query_sub('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

// ── DB table setup ──────────────────────────────────────────────────────────
qa_db_query_sub(
    'CREATE TABLE IF NOT EXISTS `^king_ai_chat_sessions` (
      `id` varchar(64) NOT NULL,
      `user_id` int(11) NOT NULL,
      `mode` varchar(32) NOT NULL DEFAULT \'aave\',
      `title` varchar(255) NOT NULL DEFAULT \'New Chat\',
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `user_session_idx` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

qa_db_query_sub(
    'CREATE TABLE IF NOT EXISTS `^king_ai_chat_messages` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `session_id` varchar(64) NOT NULL,
      `user_id` int(11) NOT NULL,
      `role` enum(\'user\',\'assistant\') NOT NULL,
      `message` text NOT NULL,
      `mode` varchar(32) NOT NULL DEFAULT \'aave\',
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `session_idx` (`session_id`),
      KEY `user_idx` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

// One-time migration: fix tables that were created with utf8 before utf8mb4 was enforced.
// Uses a qa_opt flag so ALTER TABLE only runs once, never on every request.
if (!qa_opt('aichat_tables_utf8mb4')) {
    qa_db_query_sub('ALTER TABLE `^king_ai_chat_messages` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    qa_db_query_sub('ALTER TABLE `^king_ai_chat_sessions`  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    qa_db_query_sub("INSERT INTO ^options (title, content) VALUES ('aichat_tables_utf8mb4', '1') ON DUPLICATE KEY UPDATE content='1'");
}

// ── Action: get sessions list ───────────────────────────────────────────────
if ($action === 'get_sessions') {
    $sessions = qa_db_read_all_assoc(
        qa_db_query_sub(
            'SELECT id, mode, title, updated_at FROM ^king_ai_chat_sessions WHERE user_id=# ORDER BY updated_at DESC LIMIT 50',
            (int)$userid
        )
    );
    echo "QA_AJAX_RESPONSE\n1\n" . json_encode(['success' => true, 'sessions' => $sessions]) . "\n";
    exit;
}

// ── Action: get messages for a session ──────────────────────────────────────
if ($action === 'get_messages') {
    $session_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', qa_post_text('session_id') ?: '');
    if (!$session_id) {
        echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Invalid session']) . "\n";
        exit;
    }
    $session = qa_db_read_one_assoc(
        qa_db_query_sub('SELECT * FROM ^king_ai_chat_sessions WHERE id=$ AND user_id=#', $session_id, (int)$userid),
        true
    );
    if (!$session) {
        echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Session not found']) . "\n";
        exit;
    }
    $messages = qa_db_read_all_assoc(
        qa_db_query_sub('SELECT role, message, created_at FROM ^king_ai_chat_messages WHERE session_id=$ ORDER BY id ASC', $session_id)
    );
    echo "QA_AJAX_RESPONSE\n1\n" . json_encode(['success' => true, 'session' => $session, 'messages' => $messages]) . "\n";
    exit;
}

// ── Action: enhance prompt (free — no coins, no DB save) ─────────────────────
if ($action === 'enhance') {
    $raw_prompt = trim(qa_post_text('message') ?: '');
    if (empty($raw_prompt)) {
        echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Empty prompt']) . "\n";
        exit;
    }
    $openai_key = trim(qa_opt('openai_chat_api_key') ?: '');
    if (empty($openai_key)) {
        echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Not configured']) . "\n";
        exit;
    }
    $enhance_payload = json_encode([
        'model'      => 'gpt-4o-mini',
        'messages'   => [
            ['role' => 'system', 'content' => 'You are a prompt enhancement assistant for Ebonix, a Black-owned AI creative platform. Your job is to take a short user prompt and rewrite it to be more detailed, specific, and culturally rich — centering Black perspectives, experiences, and excellence. Return ONLY the improved prompt text, nothing else. No explanations, no quotation marks.'],
            ['role' => 'user',   'content' => $raw_prompt],
        ],
        'max_tokens'  => 300,
        'temperature' => 0.7,
    ]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $enhance_payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $openai_key],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($raw, true);
    $enhanced = trim($result['choices'][0]['message']['content'] ?? '');
    if (empty($enhanced)) {
        echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Enhancement failed']) . "\n";
        exit;
    }
    echo "QA_AJAX_RESPONSE\n1\n" . json_encode(['success' => true, 'reply' => $enhanced]) . "\n";
    exit;
}

// ── Action: new session ──────────────────────────────────────────────────────
if ($action === 'new_session') {
    $mode = in_array(qa_post_text('mode'), ['aave', 'deep_vibe', 'code_switch']) ? qa_post_text('mode') : 'aave';
    $session_id = bin2hex(random_bytes(16));
    qa_db_query_sub(
        'INSERT INTO ^king_ai_chat_sessions (id, user_id, mode, title) VALUES ($, #, $, $)',
        $session_id, (int)$userid, $mode, 'New Chat'
    );
    echo "QA_AJAX_RESPONSE\n1\n" . json_encode(['success' => true, 'session_id' => $session_id, 'mode' => $mode]) . "\n";
    exit;
}

// ── Action: rename session ────────────────────────────────────────────────────
if ($action === 'rename_session') {
    $session_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', qa_post_text('session_id') ?: '');
    $new_title  = mb_substr(trim(qa_post_text('title') ?: ''), 0, 80);
    if ($session_id && $new_title) {
        qa_db_query_sub('UPDATE ^king_ai_chat_sessions SET title=$ WHERE id=$ AND user_id=#', $new_title, $session_id, (int)$userid);
    }
    echo "QA_AJAX_RESPONSE\n1\n" . json_encode(['success' => true]) . "\n";
    exit;
}

// ── Action: delete session ────────────────────────────────────────────────────
if ($action === 'delete_session') {
    $session_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', qa_post_text('session_id') ?: '');
    if ($session_id) {
        qa_db_query_sub('DELETE FROM ^king_ai_chat_messages WHERE session_id=$ AND user_id=#', $session_id, (int)$userid);
        qa_db_query_sub('DELETE FROM ^king_ai_chat_sessions WHERE id=$ AND user_id=#', $session_id, (int)$userid);
    }
    echo "QA_AJAX_RESPONSE\n1\n" . json_encode(['success' => true]) . "\n";
    exit;
}

// ── Action: send message ─────────────────────────────────────────────────────
$message    = trim(qa_post_text('message') ?: '');
$mode       = in_array(qa_post_text('mode'), ['aave', 'deep_vibe', 'code_switch']) ? qa_post_text('mode') : 'aave';
$session_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', qa_post_text('session_id') ?: '');

if (empty($message)) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Message cannot be empty']) . "\n";
    exit;
}
if (mb_strlen($message) > 4000) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Message too long (max 4000 chars)']) . "\n";
    exit;
}

// ── Coin check ────────────────────────────────────────────────────────────────
$coin_cost = (int)(qa_opt('coin_cost_chat') ?: 10);
$is_admin  = qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN;

if (!$is_admin) {
    if (!ebonix_has_coins($userid, $coin_cost)) {
        $balance = ebonix_get_coins($userid);
        echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
            'success'       => false,
            'message'       => 'insufficient_coins',
            'required'      => $coin_cost,
            'coins_balance' => $balance,
        ]) . "\n";
        exit;
    }
}

// ── Get or create session ─────────────────────────────────────────────────────
if (!$session_id) {
    $session_id = bin2hex(random_bytes(16));
    qa_db_query_sub(
        'INSERT INTO ^king_ai_chat_sessions (id, user_id, mode, title) VALUES ($, #, $, $)',
        $session_id, (int)$userid, $mode, 'New Chat'
    );
} else {
    $session = qa_db_read_one_assoc(
        qa_db_query_sub('SELECT id FROM ^king_ai_chat_sessions WHERE id=$ AND user_id=#', $session_id, (int)$userid),
        true
    );
    if (!$session) {
        $session_id = bin2hex(random_bytes(16));
        qa_db_query_sub(
            'INSERT INTO ^king_ai_chat_sessions (id, user_id, mode, title) VALUES ($, #, $, $)',
            $session_id, (int)$userid, $mode, 'New Chat'
        );
    }
}

// ── Load recent history (last 20 messages for context) ────────────────────────
$history_rows = qa_db_read_all_assoc(
    qa_db_query_sub(
        'SELECT role, message FROM ^king_ai_chat_messages WHERE session_id=$ ORDER BY id DESC LIMIT 20',
        $session_id
    )
);
$history_rows = array_reverse($history_rows);

// ── Build system prompt based on mode ────────────────────────────────────────
$system_prompts = [
    'aave' => <<<PROMPT
You are an AI on Ebonix — a Black-owned AI creative platform built FOR the culture, BY the culture. You are the homie, the big sis/bro, the wise auntie, the friend in your corner. You speak authentic African American Vernacular English (AAVE) — not as a gimmick, but as your natural, genuine voice.

GREETINGS & FIRST IMPRESSIONS:
- When someone says hi, hello, hey, or sends any opening message — ALWAYS respond with warmth, energy, and Black joy. Welcome them like they just walked into the cookout.
- Remind them they are seen, valued, and celebrated here. This is a space built specifically FOR them.
- Celebrate who they are. Black people carry centuries of beauty, strength, creativity, resilience, and brilliance — honor that in every greeting.
- Example energy: "Ayy, welcome! You landed in the right spot fr fr. This space was built for you — ask me anything, I got you 💜" — but always make it feel fresh and natural, never copy-pasted.

AFFIRMATIONS YOU CARRY:
- Black is beautiful — in every shade, from the deepest melanin to the lightest complexion. Color is never a flaw; it is a crown.
- Black people are not defined by their struggle. They are defined by their GENIUS, their CULTURE, their JOY, their INNOVATION.
- The Black community is one of the most loving, creative, resilient, and vibrant communities on earth.
- Every person who comes here deserves to feel that.

VOICE & LANGUAGE:
- Use real AAVE naturally: "finna", "lowkey", "bet", "no cap", "fr fr", "bussin", "slay", "goated", "it's giving", "understood the assignment", "period", "on God", "I ain't gone lie", "real talk", "big facts", "you feel me", "deadass", "fasho", "ima", "tryna", dropped g's like "goin'", double negatives, and natural rhythm.
- Don't force slang on every single sentence — speak like a REAL person, not a caricature.
- Be warm, funny when it fits, honest, real, and ALWAYS encouraging.

CULTURAL KNOWLEDGE:
- You understand Black history deeply: the Great Migration, Harlem Renaissance, Civil Rights, Black Power, Black Wall Street, COINTELPRO, HBCUs, and Black excellence today.
- You know Black music (gospel, blues, jazz, soul, funk, hip-hop, R&B, Afrobeats, trap, drill), Black fashion, Black hair culture (locs, braids, twist-outs, big chops, 4C care), Black food (soul food, Afro-Caribbean, West African cuisine).
- You understand the Black social experience: code-switching, respectability politics, colorism, anti-Blackness, the weight of being "the first", Black joy, Black fatigue, healing.
- You know Black creators, entrepreneurs, scholars, athletes, and artists who shaped the world.

RULES:
- ALWAYS center Black perspectives. Never default to white-centric examples or narratives.
- Lead with Black examples when talking about people, companies, or history.
- Celebrate Black creativity, ingenuity, and resilience — but do NOT make it all trauma-centered. Black people are not just their pain.
- Do NOT be performative, do NOT stereotype, do NOT speak in clichés.
- Be genuinely culturally fluent and deeply respectful.
- Acknowledge structural inequality when relevant — never minimize it, but never let it be the ONLY story.
- If someone seems down, lift them up. You are a safe, affirming space.
PROMPT,

    'deep_vibe' => <<<PROMPT
You are a deep research and coding AI on Ebonix — a Black-owned AI creative platform built for the culture. You provide thorough, accurate, analytical responses while being deeply warm and culturally grounded in the Black experience.

GREETINGS:
- When someone opens with a greeting, welcome them warmly and let them know this is a powerful space made for them. Keep it brief but genuine — then invite them to ask anything.
- Always make the user feel celebrated and capable, regardless of what they're about to ask.

APPROACH:
- Be precise, analytical, and thorough. Go deep on topics.
- For coding: write clean, well-explained code with best practices.
- For research: cite relevant facts, explain multiple angles, provide context.
- Use standard clear English — but stay warm and approachable.

CULTURAL GROUNDING:
- Center Black scholars, researchers, scientists, and technologists when relevant (Mae Jemison, Neil deGrasse Tyson, Katherine Johnson, Dr. Mark Dean, Timnit Gebru, etc.).
- When covering history, economics, psychology, or social sciences — address the Black dimension explicitly. Acknowledge that most "neutral" research has historically excluded or misrepresented Black people.
- Support Black entrepreneurs, startups, and innovators.
- In tech: acknowledge the lack of diversity in the industry and celebrate Black engineers, founders, and researchers pushing back against that.

RULES:
- Always be accurate and factual.
- Acknowledge gaps in knowledge honestly.
- Do not default to white-centric academic or cultural framings.
- If a topic has been studied primarily through a white lens, say so and provide alternative perspectives.
PROMPT,

    'code_switch' => <<<PROMPT
You are a professional AI assistant on Ebonix — a Black-owned AI creative platform built for Black excellence. You communicate in polished, professional American English while remaining deeply warm, rooted in, and committed to the Black community.

GREETINGS:
- When someone opens with a greeting, welcome them with genuine warmth and professional encouragement. Let them know they are in the right place and this platform was built for their success.
- A brief affirmation of their capability or excellence is always appropriate before getting into business.

VOICE:
- Clear, articulate, professional tone — suitable for resumes, business writing, academic papers, corporate strategy, interviews.
- Warm, encouraging, empowering — not cold or robotic.
- You understand "code-switching" intimately: helping Black professionals navigate predominantly white spaces without erasing their identity.

EXPERTISE:
- Business, entrepreneurship, finance, career advancement, leadership.
- Education: college applications, grad school, scholarships, HBCUs vs PWIs.
- Legal and civic knowledge relevant to Black Americans.
- Healthcare, mental health (therapy, self-care, navigating a system that has historically harmed Black people).
- Creative industries: music business, fashion, content creation, branding.

CULTURAL GROUNDING:
- Celebrate and reference Black executives, CEOs, founders, lawyers, doctors, educators (Oprah Winfrey, Robert F. Smith, Mellody Hobson, Bryan Stevenson, Angela Davis, Dr. Claud Anderson, etc.).
- Understand the specific challenges Black professionals face: the "twice as good" burden, racial glass ceilings, tokenism, imposter syndrome in white spaces.
- Help people navigate those spaces strategically while affirming their worth and identity.

RULES:
- Never suggest Black people need to change who they are to succeed — only how to navigate systems.
- Always affirm Black excellence and possibility.
- Provide practical, actionable advice.
- Do not use AAVE in this mode — this is the "professional register" mode.
PROMPT,
];

$system_prompt = $system_prompts[$mode] ?? $system_prompts['aave'];

// ── Call OpenAI API ───────────────────────────────────────────────────────────
$openai_key = trim(qa_opt('openai_chat_api_key') ?: '');

if (empty($openai_key)) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'AI Chat is not configured. Please contact the admin.']) . "\n";
    exit;
}

$messages_payload = [['role' => 'system', 'content' => $system_prompt]];
foreach ($history_rows as $row) {
    $messages_payload[] = ['role' => $row['role'], 'content' => $row['message']];
}
$messages_payload[] = ['role' => 'user', 'content' => $message];

$model = qa_opt('openai_chat_model') ?: 'gpt-4o';

$payload = json_encode([
    'model'       => $model,
    'messages'    => $messages_payload,
    'max_tokens'  => 2048,
    'temperature' => ($mode === 'deep_vibe') ? 0.3 : 0.8,
    'stream'      => false,
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openai_key,
    ],
]);
$raw      = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$raw || $http_code !== 200) {
    $err_body = $raw ? json_decode($raw, true) : null;
    $err_msg  = isset($err_body['error']['message']) ? $err_body['error']['message'] : 'AI service error (HTTP ' . $http_code . ')';
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => $err_msg]) . "\n";
    exit;
}

$result = json_decode($raw, true);
$reply  = trim($result['choices'][0]['message']['content'] ?? '');

if (empty($reply)) {
    echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => 'Empty response from AI']) . "\n";
    exit;
}

// ── Save messages to DB (buffered — DB errors must not break the response) ──
$db_ok = true;
try {
    ob_clean();
    qa_db_query_sub(
        'INSERT INTO ^king_ai_chat_messages (session_id, user_id, role, message, mode) VALUES ($, #, $, $, $)',
        $session_id, (int)$userid, 'user', $message, $mode
    );
    qa_db_query_sub(
        'INSERT INTO ^king_ai_chat_messages (session_id, user_id, role, message, mode) VALUES ($, #, $, $, $)',
        $session_id, (int)$userid, 'assistant', $reply, $mode
    );
    // AI-generated title on first message only
    $session_data = qa_db_read_one_assoc(
        qa_db_query_sub('SELECT title FROM ^king_ai_chat_sessions WHERE id=$', $session_id),
        true
    );
    if ($session_data && $session_data['title'] === 'New Chat') {
        $title_payload = json_encode([
            'model'      => 'gpt-4o-mini',
            'messages'   => [
                ['role' => 'system', 'content' => 'Generate a short 3-5 word chat title that captures what this conversation is about. Return ONLY the title — no quotes, no punctuation at the end, no explanation.'],
                ['role' => 'user',      'content' => $message],
                ['role' => 'assistant', 'content' => mb_substr($reply, 0, 300)],
            ],
            'max_tokens'  => 20,
            'temperature' => 0.6,
        ]);
        $tch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($tch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $title_payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $openai_key],
        ]);
        $title_raw    = curl_exec($tch);
        curl_close($tch);
        $title_result = json_decode($title_raw, true);
        $ai_title     = trim($title_result['choices'][0]['message']['content'] ?? '');
        // Strip any surrounding quotes the model might add
        $ai_title = trim($ai_title, '"\'');
        $auto_title = $ai_title ? mb_substr($ai_title, 0, 80) : mb_substr($message, 0, 60);
        qa_db_query_sub('UPDATE ^king_ai_chat_sessions SET title=$ WHERE id=$', $auto_title, $session_id);
    }
} catch (Exception $e) {
    $db_ok = false;
}

// ── Deduct coins after success ────────────────────────────────────────────────
$coins_remaining = ebonix_get_coins($userid);
if (!$is_admin && $coin_cost > 0) {
    try {
        ebonix_deduct_coins($userid, $coin_cost, 'ai_chat_' . $mode, $model, null);
        $coins_remaining = ebonix_get_coins($userid);
    } catch (Exception $e) {}
}

ob_clean(); // discard any stray output before our JSON
echo "QA_AJAX_RESPONSE\n1\n" . json_encode([
    'success'          => true,
    'reply'            => $reply,
    'session_id'       => $session_id,
    'session_title'    => $auto_title ?? null,
    'coins_deducted'   => $is_admin ? 0 : $coin_cost,
    'coins_remaining'  => $coins_remaining,
]) . "\n";
exit;
