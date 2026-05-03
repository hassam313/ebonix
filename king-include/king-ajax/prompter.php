<?php
/*
 * File: king-include/king-ajax/prompter.php
 * Purpose: Enhance user prompts with deep Black hair/beauty/culture context via Gemini Flash.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../');
    exit;
}

$prompt        = trim((string)(qa_post_text('prompt')        ?? ''));
$style_context = trim((string)(qa_post_text('style_context') ?? '')); // chip selections

if (empty($prompt) && empty($style_context)) {
    echo "QA_AJAX_RESPONSE\n1\n";
    echo json_encode(['success' => false, 'message' => 'Please enter a prompt first.']);
    echo "\n";
    exit;
}

// ── System prompt — tuned for Black hair, beauty, and culture ────────────────
$system_instruction = <<<'SYS'
You are an expert AI stylist and art director for Ebonix, an AI creative platform built around Black culture, beauty, and identity.

Your job: take the user's rough prompt and turn it into a vivid, specific, visually stunning description for AI image generation.

YOU DEEPLY KNOW:

Black women's hair:
- Box braids, knotless braids, butterfly locs, goddess locs, Senegalese twists, Bantu knots, natural 4C afro, coils, finger waves, silk press, lace-front wig, half-up bun, high ponytail, sleek low ponytail, crown braids, Ghana braids, faux locs, jumbo crochet braids, bob wig, asymmetric cut

Black men's grooming:
- Low fade, high fade, skin fade, taper fade, fresh shape-up/lineup, 360 waves, locs/dreadlocks, afro, flat top, braids, mid-part twist, Caesar cut, beard fade, full beard, goatee, clean shave

Beauty styles:
- Soft glam: glowing skin, neutral eyeshadow, lash extensions, glossy nude lip, dewy finish
- Bold glam: smoky eye, sharp contour, red or berry lip, dramatic lashes
- Editorial: avant-garde makeup, graphic liner, sculptural beauty
- Natural: bare skin, SPF glow, clear gloss, subtle mascara
- Old money: understated luxury, minimal makeup, perfect skin

Skin tones — always celebrate and enhance:
- Deep ebony, rich melanin, warm mahogany, golden brown, caramel, dark chocolate, blue-black undertones

Cultural aesthetics: Afro-futurism, streetwear, luxury fashion, soft life aesthetic, Black girl magic, bougie casual, church girl glam, HBCU culture, editorial magazine

Lighting vocab: golden hour, ring light glow, studio softbox, dramatic Rembrandt, moody low-key, neon city night, outdoor sunlight, backlit silhouette

RULES:
1. Keep the subject's core idea intact — never change the concept
2. Add: specific hair detail, skin glow description, lighting, mood, background
3. Portrait prompts must include: skin celebration, hair specifics, lighting direction
4. Return ONLY the enhanced prompt — no explanation, no quotes, no preamble
5. Stay under 120 words
SYS;

// ── Build user message ────────────────────────────────────────────────────────
$user_message = 'Enhance this image generation prompt';

if (!empty($style_context)) {
    $user_message .= ' with this style context: [' . $style_context . ']';
}

if (!empty($prompt)) {
    $user_message .= "\n\nPrompt: " . $prompt;
} else {
    // Only chips selected, no free text — generate a starter prompt
    $user_message = 'Create a vivid image generation prompt based on this style context: [' . $style_context . ']. '
        . 'Make it a beautiful Black portrait with the specified style elements.';
}

// ── Call Gemini Flash ─────────────────────────────────────────────────────────
$gemini_key = qa_opt('gemini_api') ?: 'AIzaSyBy0QiG_CTinUOeFV4sd0tMKG660qHgnSw';
$gemini_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $gemini_key;

$gemini_payload = json_encode([
    'system_instruction' => ['parts' => [['text' => $system_instruction]]],
    'contents'           => [['role' => 'user', 'parts' => [['text' => $user_message]]]],
    'generationConfig'   => ['temperature' => 0.9, 'maxOutputTokens' => 250],
]);

$enhanced = '';
$ch = curl_init($gemini_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $gemini_payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw && $code === 200) {
    $json     = json_decode($raw, true);
    $enhanced = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
    // Strip any leading/trailing quotes Gemini sometimes adds
    $enhanced = trim($enhanced, '"\'');
}

// ── Fallback ─────────────────────────────────────────────────────────────────
if (empty($enhanced)) {
    $base = $prompt ?: ('Black portrait with ' . $style_context);
    $enhanced = $base . ', warm golden hour lighting, rich melanin skin, stunning detail, professional photography';
}

echo "QA_AJAX_RESPONSE\n1\n";
echo json_encode(['success' => true, 'message' => $enhanced]);
echo "\n";
