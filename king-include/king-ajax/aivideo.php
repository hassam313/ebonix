<?php
/*
File: king-include/king-ajax/aivideo.php
Description: Server-side response to Ajax AI video generation

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

// CRITICAL: Set execution time limits FIRST
set_time_limit(600); // 10 minutes for video
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

require_once QA_INCLUDE_DIR . 'king-app/users.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-app-video.php';
require_once QA_INCLUDE_DIR . 'king-app/cookies.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/gateway.php';
require_once QA_INCLUDE_DIR . 'king-app/coins.php';

if (qa_is_logged_in()) {
    $userid = qa_get_logged_in_userid();
} else {
    $userid = qa_remote_ip_address();
}

$input = qa_post_text('input');
$imsize = qa_post_text('radio');
$reso = qa_post_text('reso');
$provider = qa_post_text('model');
$imageid = qa_post_text('imageid');
function king_luma_aspect_ratio($imsize) {
    $imsize = trim((string)$imsize);
    $allowed = ['16:9','9:16','1:1','4:3','3:4'];
    return in_array($imsize, $allowed, true) ? $imsize : '16:9';
}

function king_luma_resolution($reso) {
    $reso = trim((string)$reso);
    $allowed = ['540p','720p','1080p','4k'];
    return in_array($reso, $allowed, true) ? $reso : '540p';
}

$chkk = true;
$error = '';
$videourl = '';

// ── Plan + Coin enforcement for video — cost calculated for ALL users; enforcement for non-admins only ──
$is_admin      = qa_is_logged_in() && qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN;
$vid_tier_key  = 'enhanced';   // default if not specified
$vid_tier_label = 'Enhanced Short Video';
$vid_total_cost = 0;

if (qa_is_logged_in()) {
    ebonix_ensure_initialized($userid);
    $vid_plan = ebonix_get_user_plan($userid);

    // Determine video tier and coin cost
    $auto_mode = trim((string)(isset($_POST['auto_mode']) ? $_POST['auto_mode'] : '0'));
    if ($auto_mode === '1') {
        // Keyword-based auto routing (Phase 1 LLM router)
        $prompt_lower  = strtolower((string)$input);
        $premium_kw    = ['cinematic', 'slow motion', 'kling', 'epic', 'premium', 'hd video', 'camera movement', '4k', 'ultra'];
        $pro_kw        = ['scene', 'movement', 'characters', 'story', 'interact', 'action'];
        $budget_kw     = ['preview', 'quick', 'simple', 'test', 'draft', 'rough'];
        if (array_filter($premium_kw, function($k) use ($prompt_lower) { return strpos($prompt_lower, $k) !== false; })) {
            $vid_tier_key = 'premium';
        } elseif (array_filter($pro_kw, function($k) use ($prompt_lower) { return strpos($prompt_lower, $k) !== false; })) {
            $vid_tier_key = 'pro';
        } elseif (array_filter($budget_kw, function($k) use ($prompt_lower) { return strpos($prompt_lower, $k) !== false; })) {
            $vid_tier_key = 'basic';
        } else {
            $vid_tier_key = 'enhanced';
        }
        $auto_selected_vid = true;
    } else {
        // Manual: use posted tier, or infer from provider model key
        $posted_tier = trim((string)(isset($_POST['video_tier']) ? $_POST['video_tier'] : ''));
        if ($posted_tier && array_key_exists($posted_tier, ebonix_get_video_tiers())) {
            $vid_tier_key = $posted_tier;
        } else {
            $vid_tier_key = ebonix_model_to_video_tier((string)$provider);
        }
        $auto_selected_vid = false;
    }

    $vid_tiers      = ebonix_get_video_tiers();
    $vid_tier_data  = $vid_tiers[$vid_tier_key] ?? $vid_tiers['enhanced'];
    $vid_tier_label = $vid_tier_data['label'];
    $vid_base_cost  = $vid_tier_data['coins'];

    // Add-ons
    $addon_costs    = ebonix_get_addon_costs();
    $addon_total    = 0;
    if (!empty($_POST['addon_hd']))       $addon_total += $addon_costs['hd_export'];
    if (!empty($_POST['addon_upscale']))  $addon_total += $addon_costs['upscale'];
    if (!empty($_POST['addon_priority'])) $addon_total += $addon_costs['priority'];
    $vid_total_cost = $vid_base_cost + $addon_total;

    // Enforcement checks — non-admin only (admin can always generate)
    if (!$is_admin) {
        // Free plan: video not available
        if ($vid_plan === 0) {
            echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
                'success' => false,
                'message' => 'upgrade_required',
                'feature' => 'video',
                'msg'     => 'AI Video requires the Flex plan. Get 10,000 coins/month plus full access.',
            ]) . "\n";
            exit;
        }

        if (!ebonix_has_coins($userid, $vid_total_cost)) {
            echo "QA_AJAX_RESPONSE\n0\n" . json_encode([
                'success'        => false,
                'message'        => 'insufficient_coins',
                'coins_needed'   => $vid_total_cost,
                'coins_balance'  => ebonix_get_coins($userid),
                'shortfall'      => $vid_total_cost - ebonix_get_coins($userid),
            ]) . "\n";
            exit;
        }
    }
}

if ($input && $chkk) {

    // ========== IMAGE-TO-VIDEO — Fal Kling (when reference image present) ==========
    $ref_image_b64   = trim((string)(isset($_POST['ref_image_b64']) ? $_POST['ref_image_b64'] : ''));
    $use_gateway     = qa_opt('gateway_enabled') && !empty(qa_opt('gateway_url'));
    $video_processed = false;

    if (!empty($ref_image_b64)) {
        // Strip data URI prefix and detect MIME type
        $i2v_clean_b64 = $ref_image_b64;
        $i2v_mime      = 'image/jpeg';
        if (preg_match('/^data:([^;]+);base64,(.+)$/s', $ref_image_b64, $_m)) {
            $i2v_mime      = $_m[1];
            $i2v_clean_b64 = trim($_m[2]);
        }

        if (!empty($i2v_clean_b64)) {
            $i2v_gateway_url   = 'http://127.0.0.1:8001/image_to_video';
            $i2v_gateway_token = 'ebonix_secret_12345';
            $i2v_aspect        = king_luma_aspect_ratio($imsize);

            // Map provider key to gateway provider name
            $i2v_gateway_provider = 'seedance'; // default Standard
            if ($provider === 'kling_v3') {
                $i2v_gateway_provider = 'kling_v3';
            } elseif ($provider === 'kling_v2' || $provider === 'luma_vid') {
                $i2v_gateway_provider = 'kling_v2';
            }

            $i2v_payload = json_encode([
                'image_b64'    => $i2v_clean_b64,
                'mime_type'    => $i2v_mime,
                'prompt'       => $input,
                'aspect_ratio' => $i2v_aspect,
                'provider'     => $i2v_gateway_provider,
            ]);

            $i2v_ch = curl_init($i2v_gateway_url);
            curl_setopt($i2v_ch, CURLOPT_POST,           true);
            curl_setopt($i2v_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($i2v_ch, CURLOPT_TIMEOUT,        600);
            curl_setopt($i2v_ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($i2v_ch, CURLOPT_HTTPHEADER,     [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $i2v_gateway_token,
            ]);
            curl_setopt($i2v_ch, CURLOPT_POSTFIELDS, $i2v_payload);

            $i2v_response = curl_exec($i2v_ch);
            $i2v_curl_err = curl_error($i2v_ch);
            $i2v_http     = (int)curl_getinfo($i2v_ch, CURLINFO_HTTP_CODE);
            curl_close($i2v_ch);

            error_log("aivideo i2v: HTTP={$i2v_http} curl_err=" . (string)$i2v_curl_err);

            if (!$i2v_curl_err && $i2v_http >= 200 && $i2v_http < 300) {
                $i2v_data = json_decode((string)$i2v_response, true);
                if (!empty($i2v_data['success']) && !empty($i2v_data['video_url'])) {
                    $videourl        = $i2v_data['video_url'];
                    $video_processed = true;
                    error_log("aivideo i2v: success video_url={$videourl}");
                } else {
                    $error = $i2v_data['error'] ?? 'Image-to-video generation failed';
                    error_log("aivideo i2v: gateway error: {$error}");
                }
            } else {
                $error = $i2v_curl_err ?: "Gateway HTTP {$i2v_http}";
                error_log("aivideo i2v: request failed: {$error}");
            }
        }
    }

    // If ref image was provided but i2v failed, return the error immediately.
    // Do NOT silently fall back to text-only generation when the user
    // explicitly selected a reference image.
    if (!empty($ref_image_b64) && !$video_processed) {
        echo "QA_AJAX_RESPONSE\n0\n" . json_encode(['success' => false, 'message' => $error ?: 'Image-to-video failed. Please try again.']) . "\n";
        exit;
    }

    // ========== GATEWAY ROUTING (text-to-video, skipped when i2v succeeded) ==========

    if ($use_gateway && !$video_processed) {
        try {
            $gateway_result = Ebonix_Gateway::generate_video(
                $input,
                $provider,
                $imsize,
                $reso,
                !empty($imageid) ? king_get_uploads($imageid)['furl'] ?? null : null
            );
            
            if (!isset($gateway_result['error'])) {
                if (isset($gateway_result['job_id'])) {
                    // Job-based video (will poll later)
                    $output = json_encode([
                        'success' => true,
                        'job_id' => $gateway_result['job_id'],
                        'status' => 'processing'
                    ]);
                    echo "QA_AJAX_RESPONSE\n1\n" . $output . "\n";
                    exit;
                    
                } elseif (isset($gateway_result['video_url'])) {
                    // ✅ GATEWAY VIDEO PROCESSING - SAME AS DIRECT API
                    $videourl = $gateway_result['video_url'];
                    
                    error_log("Gateway video: Processing URL = $videourl");
                    
                    // Download and upload video
                    require_once QA_INCLUDE_DIR . 'king-app/post-create.php';
                    $extra = king_urlupload($videourl);
                    
                    if (empty($extra)) {
                        error_log("Gateway video: Failed to upload video");
                        $error = 'Failed to upload video from gateway';
                    } else {
                        // Create post
                        $thumb = null;
                        $cookieid = isset($userid) ? qa_cookie_get() : qa_cookie_get_create();
                        
                        $postid = qa_question_create(
                            null, 
                            $userid, 
                            qa_get_logged_in_handle(), 
                            $cookieid, 
                            null, 
                            $thumb, 
                            '', 
                            null, null, null, null, null, 
                            $extra, 
                            'NOTE', 
                            null, 
                            'aivid', 
                            $input, 
                            null
                        );
                        
                        // Set metadata
                        qa_db_postmeta_set($postid, 'wai', true);
                        qa_db_postmeta_set($postid, 'model', $provider);
                        
                        if ($reso) {
                            qa_db_postmeta_set($postid, 'stle', $reso);
                        }
                        if (isset($imsize)) {
                            qa_db_postmeta_set($postid, 'asize', $imsize);
                        }
                        if ($imageid) {
                            qa_db_postmeta_set($postid, 'pimage', $imageid);
                        }
                        
                        // Update credits
                        if (qa_opt('enable_membership') && (qa_opt('ailimits') || qa_opt('ulimits'))) {
                            kingai_imagen(1);
                        }
                        
                        error_log("Gateway video: Post created successfully, postid = $postid");
                        
                        // Return success
                        $output = json_encode([
                            'success' => true,
                            'postid' => $postid,
                            'videourl' => $videourl
                        ]);
                        
                        echo "QA_AJAX_RESPONSE\n1\n";
                        echo $output . "\n";
                        echo king_ai_posts($userid, 'aivid');
                        exit;
                    }
                }
            } else {
                error_log("Gateway video: Error - " . ($gateway_result['error'] ?? 'Unknown'));
            }
        } catch (Exception $e) {
            error_log("Gateway video: Exception - " . $e->getMessage());
            // Fall through to direct provider calls
        }
    }
    
    // ========== DIRECT PROVIDER CALLS (skipped when i2v or gateway already produced a result) ==========
    if (!$video_processed) {

    // ========== VEO 3 / VEO 3 FAST ==========
    if ($provider === 'veo3' || $provider === 'veo3f') {
        $API_KEY = qa_opt('gemini_api');

        if ($provider === 'veo3f') {
            $api_url = "https://generativelanguage.googleapis.com/v1beta/models/veo-3.1-fast-generate-preview:predictLongRunning?key=" . $API_KEY;
        } else {
            $api_url = "https://generativelanguage.googleapis.com/v1beta/models/veo-3.1-generate-preview:predictLongRunning?key=" . $API_KEY;
        }

        $payload = [
            "instances" => [
                ["prompt" => $input]
            ]
        ];

        if (!empty($_POST['file_uri'])) {
            $payload["instances"][0]["file"] = [
                "file_uri" => $_POST['file_uri'],
            ];
        }

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = "API Error: " . curl_error($ch);
            curl_close($ch);
        } else {
            curl_close($ch);
            
            $data = json_decode($response, true);

            if (!isset($data['name'])) {
                $error = 'Failed to get operation name from Gemini Veo 3.1 API.';
            } else {
                $operation_name = $data['name'];

                $video_uri = '';
                $max_attempts = 60;
                $attempt = 0;
                $sleep_time = 10;

                while ($attempt < $max_attempts) {
                    $status_url = "https://generativelanguage.googleapis.com/v1beta/" . $operation_name . "?key=" . $API_KEY;

                    $ch = curl_init($status_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

                    $status_response = curl_exec($ch);
                    
                    if (curl_errno($ch)) {
                        error_log("Polling error: " . curl_error($ch));
                        curl_close($ch);
                        sleep($sleep_time);
                        $attempt++;
                        continue;
                    }
                    
                    curl_close($ch);

                    $status = json_decode($status_response, true);

                    if (isset($status['done']) && $status['done'] === true) {
                        $video_uri = $status['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'] ?? null;

                        if ($video_uri) {
                            $videourl = $video_uri . (strpos($video_uri, '?') === false ? '?' : '&') . 'key=' . $API_KEY;
                        }
                        break;
                    } elseif (isset($status['error'])) {
                        $error = 'Veo 3.1 returned error: ' . json_encode($status['error']);
                        break;
                    } else {
                        sleep($sleep_time);
                        $attempt++;
                    }
                }

                if (empty($videourl) && empty($error)) {
                    $error = 'Veo 3.1 video generation timed out after ' . ($max_attempts * $sleep_time) . ' seconds.';
                }
            }
        }

    } 
    // ========== DECART VIDEO ==========
    elseif ($provider === 'decart_vid') {
        $API_KEY = qa_opt('decart_api');
        
        if (empty($API_KEY)) {
            $error = 'Decart API key not configured';
        } else {
            // Determine which Decart endpoint to use
            if ($imageid) {
                // Image-to-video
                $api_url = "https://api.decart.ai/v1/jobs/lucy-pro-i2v";
                $image_info = king_get_uploads($imageid);
                $file_path = isset($image_info['path']) ? $image_info['path'] : '';
            } else {
                // Text-to-video (no input file) or video transformation
                $api_url = "https://api.decart.ai/v1/jobs/lucy-pro-t2v";
                $file_path = '';
            }
            
            // Build multipart form data
            $boundary = '----WebKitFormBoundary' . uniqid();
            $body = '';
            
            // Add prompt
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"prompt\"\r\n\r\n";
            $body .= $input . "\r\n";
            
            // Add file if exists (for image-to-video)
            if ($file_path && file_exists($file_path)) {
                $file_data = file_get_contents($file_path);
                $file_name = basename($file_path);
                $mime_type = mime_content_type($file_path);
                
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"data\"; filename=\"{$file_name}\"\r\n";
                $body .= "Content-Type: {$mime_type}\r\n\r\n";
                $body .= $file_data . "\r\n";
            }
            
            $body .= "--{$boundary}--\r\n";
            
            // Submit job to Decart
            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-API-KEY: $API_KEY",
                "Content-Type: multipart/form-data; boundary={$boundary}"
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                $error = "Decart API Error: " . curl_error($ch);
            }
            
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if (!$error) {
                $out = json_decode($response, true);
                
                if ($http_code !== 200 && $http_code !== 201) {
                    $error = 'Decart API returned HTTP ' . $http_code . ': ' . ($out['error']['message'] ?? $response);
                } elseif (isset($out['error'])) {
                    $error = $out['error']['message'] ?? 'Decart video generation failed';
                } elseif (isset($out['job_id'])) {
                    // Job submitted - poll for completion
                    $job_id = $out['job_id'];
                    $max_attempts = 120; // 10 minutes (120 * 5s = 600s)
                    $attempt = 0;
                    $sleep_time = 5;
                    
                    while ($attempt < $max_attempts) {
                        sleep($sleep_time);
                        
                        $status_url = "https://api.decart.ai/v1/jobs/{$job_id}";
                        $ch = curl_init($status_url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            "X-API-KEY: $API_KEY"
                        ]);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                        
                        $status_response = curl_exec($ch);
                        
                        if (curl_errno($ch)) {
                            error_log("Decart polling error: " . curl_error($ch));
                            curl_close($ch);
                            $attempt++;
                            continue;
                        }
                        
                        curl_close($ch);
                        
                        $status = json_decode($status_response, true);
                        
                        if (isset($status['status']) && $status['status'] === 'completed') {
                            // Download video
                            $download_url = "https://api.decart.ai/v1/jobs/{$job_id}/content";
                            $ch = curl_init($download_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                "X-API-KEY: $API_KEY"
                            ]);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
                            
                            $video_data = curl_exec($ch);
                            
                            if (curl_errno($ch)) {
                                $error = "Failed to download video: " . curl_error($ch);
                            } else {
                                // Save to temporary file
                                $temp_dir = sys_get_temp_dir();
                                $temp_file = $temp_dir . '/decart_video_' . uniqid() . '.mp4';
                                
                                if (file_put_contents($temp_file, $video_data)) {
                                    // Create a URL-like reference for king_urlupload
                                    // Since we have local file, we'll handle upload differently
                                    require_once QA_INCLUDE_DIR . 'king-app/blobs.php';
                                    require_once QA_INCLUDE_DIR . 'king-app/post-create.php';
                                    
                                    $folder = 'uploads/' . date("Y") . '/' . date("m") . '/';
                                    $destDir = QA_INCLUDE_DIR . $folder;
                                    
                                    if (!file_exists($destDir)) {
                                        mkdir($destDir, 0777, true);
                                    }
                                    
                                    $finalFilename = 'decart-video-' . time() . '-' . mt_rand(1000, 9999) . '.mp4';
                                    $finalPath = $destDir . $finalFilename;
                                    
                                    if (copy($temp_file, $finalPath)) {
                                        // Upload to cloud if enabled
                                        if (qa_opt('enable_aws')) {
                                            $videourl = king_upload_to_cloud($finalPath, $finalFilename, 'aws');
                                            $extra = king_insert_uploads($videourl, 'mp4', 0, 0, 'aws');
                                        } elseif (qa_opt('enable_wasabi')) {
                                            $videourl = king_upload_to_cloud($finalPath, $finalFilename, 'wasabi');
                                            $extra = king_insert_uploads($videourl, 'mp4', 0, 0, 'wasabi');
                                        } else {
                                            $videourl = qa_path_to_root() . $folder . $finalFilename;
                                            $extra = king_insert_uploads($folder . $finalFilename, 'mp4', 0, 0);
                                        }
                                    }
                                    
                                    @unlink($temp_file);
                                }
                            }
                            
                            curl_close($ch);
                            break;
                        } elseif (isset($status['status']) && $status['status'] === 'failed') {
                            $error = 'Decart video generation failed: ' . ($status['error']['message'] ?? 'Unknown error');
                            break;
                        }
                        
                        $attempt++;
                    }
                    
                    if (empty($videourl) && empty($error) && empty($extra)) {
                        $error = 'Decart video generation timed out after 10 minutes';
                    }
                } else {
                    $error = 'Invalid response from Decart API';
                }
            }
        }
    } 
    // ========== SEEDANCE VIDEO (Standard — via Ebonix Gateway) ==========
    elseif ($provider === 'seedance_vid') {
        $sdance_url   = 'http://127.0.0.1:8001/text_to_video';
        $sdance_token = 'ebonix_secret_12345';
        $sdance_ar    = king_luma_aspect_ratio($imsize);
        $sdance_dur   = trim((string)(isset($_POST['duration']) ? $_POST['duration'] : '5'));
        if (!in_array($sdance_dur, ['5','10','15','30','60'], true)) {
            $sdance_dur = '5';
        }

        $sdance_payload = json_encode([
            'prompt'       => $input,
            'aspect_ratio' => $sdance_ar,
            'duration'     => $sdance_dur,
            'provider'     => 'seedance',
        ]);

        $sdance_ch = curl_init($sdance_url);
        curl_setopt($sdance_ch, CURLOPT_POST,           true);
        curl_setopt($sdance_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($sdance_ch, CURLOPT_TIMEOUT,        600);
        curl_setopt($sdance_ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($sdance_ch, CURLOPT_HTTPHEADER,     [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $sdance_token,
        ]);
        curl_setopt($sdance_ch, CURLOPT_POSTFIELDS, $sdance_payload);

        $sdance_response = curl_exec($sdance_ch);
        $sdance_curl_err = curl_error($sdance_ch);
        $sdance_http     = (int)curl_getinfo($sdance_ch, CURLINFO_HTTP_CODE);
        curl_close($sdance_ch);

        error_log("aivideo seedance_vid: HTTP={$sdance_http} curl_err=" . (string)$sdance_curl_err);

        if (!$sdance_curl_err && $sdance_http >= 200 && $sdance_http < 300) {
            $sdance_data = json_decode((string)$sdance_response, true);
            if (!empty($sdance_data['success']) && !empty($sdance_data['video_url'])) {
                $videourl = $sdance_data['video_url'];
                error_log("aivideo seedance_vid: success video_url={$videourl}");
            } else {
                $error = $sdance_data['error'] ?? 'Seedance video generation failed';
                error_log("aivideo seedance_vid: gateway error: {$error}");
            }
        } else {
            $error = $sdance_curl_err ?: "Gateway HTTP {$sdance_http}";
            error_log("aivideo seedance_vid: request failed: {$error}");
        }
    }
    // ========== KLING V3 PRO VIDEO (Premium — via Ebonix Gateway) ==========
    elseif ($provider === 'kling_v3') {
        $kv3_url   = 'http://127.0.0.1:8001/text_to_video';
        $kv3_token = 'ebonix_secret_12345';
        $kv3_ar    = king_luma_aspect_ratio($imsize);
        $kv3_dur   = trim((string)(isset($_POST['duration']) ? $_POST['duration'] : '5'));
        if (!in_array($kv3_dur, ['5','10','15','30','60'], true)) {
            $kv3_dur = '5';
        }

        $kv3_payload = json_encode([
            'prompt'       => $input,
            'aspect_ratio' => $kv3_ar,
            'duration'     => $kv3_dur,
            'provider'     => 'kling_v3',
        ]);

        $kv3_ch = curl_init($kv3_url);
        curl_setopt($kv3_ch, CURLOPT_POST,           true);
        curl_setopt($kv3_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($kv3_ch, CURLOPT_TIMEOUT,        600);
        curl_setopt($kv3_ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($kv3_ch, CURLOPT_HTTPHEADER,     [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $kv3_token,
        ]);
        curl_setopt($kv3_ch, CURLOPT_POSTFIELDS, $kv3_payload);

        $kv3_response = curl_exec($kv3_ch);
        $kv3_curl_err = curl_error($kv3_ch);
        $kv3_http     = (int)curl_getinfo($kv3_ch, CURLINFO_HTTP_CODE);
        curl_close($kv3_ch);

        error_log("aivideo kling_v3: HTTP={$kv3_http} curl_err=" . (string)$kv3_curl_err);

        if (!$kv3_curl_err && $kv3_http >= 200 && $kv3_http < 300) {
            $kv3_data = json_decode((string)$kv3_response, true);
            if (!empty($kv3_data['success']) && !empty($kv3_data['video_url'])) {
                $videourl = $kv3_data['video_url'];
                error_log("aivideo kling_v3: success video_url={$videourl}");
            } else {
                $error = $kv3_data['error'] ?? 'Kling v3 video generation failed';
                error_log("aivideo kling_v3: gateway error: {$error}");
            }
        } else {
            $error = $kv3_curl_err ?: "Gateway HTTP {$kv3_http}";
            error_log("aivideo kling_v3: request failed: {$error}");
        }
    }
    // ========== LUMA VIDEO (legacy) ==========
    elseif ($provider === 'luma_vid') {
    $API_KEY = qa_opt('luma_api');

    if (empty($API_KEY)) {
        $error = 'Luma API key not configured';
    } else {

        // ✅ correct endpoint for video generations (per Luma docs)
        $api_url = "https://api.lumalabs.ai/dream-machine/v1/generations/video";

        // map ui -> luma params
        $aspect = king_luma_aspect_ratio($imsize);
        $res    = king_luma_resolution($reso);

        // ✅ IMPORTANT: model is required for video
        // choose one:
        // - ray-2 (better quality)
        // - ray-flash-2 (faster)
        $payload = [
            'prompt'       => $input,
'model' => 'ray-flash-2',
            'aspect_ratio' => $aspect,
            'resolution'   => $res,
            'duration'     => '5s',
        ];

        // image-to-video (start frame)
        if (!empty($imageid)) {
            $image_info = king_get_uploads($imageid);
            if (!empty($image_info['furl'])) {
                $payload['keyframes'] = [
                    'frame0' => [
                        'type' => 'image',
                        'url'  => $image_info['furl'],
                    ]
                ];
            }
        }

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $API_KEY,
            "Content-Type: application/json",
            "Accept: application/json",
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = "Luma API Error: " . curl_error($ch);
            curl_close($ch);
        } else {
            curl_close($ch);

            $out = json_decode($response, true);

            // ✅ luma errors often come in "detail"
            if ($http_code !== 200 && $http_code !== 201) {
                $msg = $out['detail'] ?? $out['error'] ?? $response;
                $error = 'Luma HTTP ' . $http_code . ': ' . (is_string($msg) ? $msg : json_encode($msg));
            } elseif (!empty($out['error'])) {
                $error = is_string($out['error']) ? $out['error'] : json_encode($out['error']);
            } elseif (!empty($out['id'])) {

                $generation_id = $out['id'];

                // poll for completion
                $max_attempts = 180; // 15 min (180 * 5s)
                $attempt = 0;
                $sleep_time = 5;

                while ($attempt < $max_attempts) {
                    sleep($sleep_time);

                    $status_url = "https://api.lumalabs.ai/dream-machine/v1/generations/{$generation_id}";
                    $ch2 = curl_init($status_url);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                        "Authorization: Bearer " . $API_KEY,
                        "Accept: application/json",
                    ]);
                    curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 10);

                    $status_response = curl_exec($ch2);

                    if (curl_errno($ch2)) {
                        error_log("Luma polling error: " . curl_error($ch2));
                        curl_close($ch2);
                        $attempt++;
                        continue;
                    }

                    curl_close($ch2);

                    $status = json_decode($status_response, true);

                    if (!empty($status['state']) && $status['state'] === 'completed') {
                        // assets.video is usually a direct mp4 url
                        $videourl = $status['assets']['video'] ?? '';
                        break;
                    }

                    if (!empty($status['state']) && $status['state'] === 'failed') {
                        $error = 'Luma video generation failed: ' . ($status['failure_reason'] ?? 'Unknown error');
                        break;
                    }

                    $attempt++;
                }

                if (empty($videourl) && empty($error)) {
                    $error = 'Luma video generation timed out after ' . ($max_attempts * $sleep_time) . ' seconds.';
                }

            } else {
                $error = 'Invalid response from Luma API';
            }
        }
    }
}
    // ========== OTHER VIDEO MODELS (KingStudio and legacy providers) ==========
    else {
        $api_url = "https://kingstudio.io/api/king-text2video";
        $api_key = qa_opt('king_sd_api');

        $request_data = [
            "prompt" => $input,
            "aisize" => $imsize,
            "model" => $provider,
            "reso" => $reso,
        ];
        
        if ($imageid) {
            $imageurl = king_get_uploads($imageid);
            $request_data['image'] = $imageurl['furl'];
        }

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $api_key",
            "Accept: application/json",
            "Content-Type: application/json",
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = "API Error: " . curl_error($ch);
        }
        
        curl_close($ch);

        if (!$error) {
            $out = json_decode($response, true);
            if (isset($out['error'])) {
                $error = $out['error'];
            } else {
                $videourl = $out['out'] ?? '';
            }
        }
    }
    } // end if (!$video_processed) — direct provider calls

    // ========== PROCESS RESULTS ==========
    if (isset($error) && $error) {
        $output = json_encode(array('success' => false, 'message' => $error));
        echo "QA_AJAX_RESPONSE\n0\n";
        echo $output . "\n";
    } else {
        // For Decart, we already have $extra set from the upload process
        if ($provider === 'decart_vid' && !empty($extra)) {
            // Video already uploaded, create post
            $thumb = null;
            $cookieid = isset($userid) ? qa_cookie_get() : qa_cookie_get_create();
            
            $postid = qa_question_create(null, $userid, qa_get_logged_in_handle(), $cookieid, null, $thumb, '', null, null, null, null, null, $extra, 'NOTE', null, 'aivid', $input, null);
            
            qa_db_postmeta_set($postid, 'wai', true);
            qa_db_postmeta_set($postid, 'model', $provider);

            if ($reso) {
                qa_db_postmeta_set($postid, 'stle', $reso);
            }
            if (isset($imsize)) {
                qa_db_postmeta_set($postid, 'asize', $imsize);
            }
            if ($imageid) {
                qa_db_postmeta_set($postid, 'pimage', $imageid);
            }
            
            if (qa_opt('enable_membership') && (qa_opt('ailimits') || qa_opt('ulimits'))) {
                kingai_imagen(1);
            }

            $output = json_encode(array(
                'success' => true,
                'postid' => $postid,
                'videourl' => $videourl
            ));

            echo "QA_AJAX_RESPONSE\n1\n";
            echo $output . "\n";
            echo king_ai_posts($userid, 'aivid');
            
        } elseif (empty($videourl)) {
            $output = json_encode(array('success' => false, 'message' => 'Failed to generate video'));
            echo "QA_AJAX_RESPONSE\n0\n";
            echo $output . "\n";
        } else {
            // For other providers, use existing upload flow
            require_once QA_INCLUDE_DIR . 'king-app/post-create.php';
            
            $extra = king_urlupload($videourl);

            if (empty($extra)) {
                $output = json_encode(array('success' => false, 'message' => 'Failed to upload video'));
                echo "QA_AJAX_RESPONSE\n0\n";
                echo $output . "\n";
            } else {
                $thumb = null;
                $cookieid = isset($userid) ? qa_cookie_get() : qa_cookie_get_create();
                
                $postid = qa_question_create(null, $userid, qa_get_logged_in_handle(), $cookieid, null, $thumb, '', null, null, null, null, null, $extra, 'NOTE', null, 'aivid', $input, null);
                
                qa_db_postmeta_set($postid, 'wai', true);
                qa_db_postmeta_set($postid, 'model', $provider);

                if ($reso) {
                    qa_db_postmeta_set($postid, 'stle', $reso);
                }
                if (isset($imsize)) {
                    qa_db_postmeta_set($postid, 'asize', $imsize);
                }
                if ($imageid) {
                    qa_db_postmeta_set($postid, 'pimage', $imageid);
                }
                
                // ── Deduct coins on confirmed video success (all logged-in users) ─
                $vid_new_balance = 0;
                if (qa_is_logged_in() && $vid_total_cost > 0) {
                    $vid_new_balance = ebonix_deduct_coins(
                        $userid, $vid_total_cost,
                        'video_' . $vid_tier_key,
                        (string)$provider
                    );
                } elseif (qa_is_logged_in()) {
                    $vid_new_balance = ebonix_get_coins($userid);
                }

                $output = json_encode(array(
                    'success'          => true,
                    'postid'           => $postid,
                    'videourl'         => $videourl,
                    'model_label'      => $vid_tier_label,
                    'auto_selected'    => isset($auto_selected_vid) ? $auto_selected_vid : false,
                    'coins_deducted'   => $vid_total_cost,
                    'coins_remaining'  => $vid_new_balance,
                ));

                echo "QA_AJAX_RESPONSE\n1\n";
                echo $output . "\n";
                echo king_ai_posts($userid, 'aivid');
            }
        }
    }

} else {
    $outputz = json_encode(array('success' => false, 'message' => qa_lang_html('misc/nocredits')));
    echo "QA_AJAX_RESPONSE\n0\n";
    echo $outputz . "\n";
}
