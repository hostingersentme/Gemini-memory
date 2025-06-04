<?php
session_start();

// --- Error Handling & Logging Setup ---
ini_set('display_errors', 0); // Don't display errors directly to users
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'gp_model_debug.log'); // Centralized log file
error_reporting(E_ALL); // Log all errors

// --- Constants and Configuration ---
define('CODE_BASE_DIR', __DIR__ . '/code_files'); // Base directory for code files

//for long term memory
if (!isset($_SESSION['ltm_saved_this_segment'])) {
    $_SESSION['ltm_saved_this_segment'] = [];   // IDs saved since last clear
}

// Include external configurations securely
// API Key (Should contain: <?php $geminiApiKey = 'YOUR_API_KEY';)
require 'api_key.php';
require_once __DIR__ . '/db_handler.php'; // Added for long-term memory DB access
if (empty($geminiApiKey)) {
    gp_model_write_debug_log("CRITICAL: Gemini API key is missing or empty in api_key.php.");
    // Immediately exit with a generic error if API key is missing
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'error' => 'Server configuration error [API Key].']);
    exit();
}

// reCAPTCHA Config (Should return an array: return ['secret_key' => 'YOUR_SECRET'];)
$recaptcha_config = require 'recaptcha_config.php';
if (!is_array($recaptcha_config) || empty($recaptcha_config['secret_key'])) {
     gp_model_write_debug_log("CRITICAL: reCAPTCHA configuration is missing or invalid in recaptcha_config.php.");
     header('Content-Type: application/json; charset=UTF-8');
     echo json_encode(['status' => 'error', 'error' => 'Server configuration error [reCAPTCHA].']);
     exit();
}

// System Messages (Should return an array: return ['Ava' => 'Prompt for Ava', 'Gala' => '...', 'Charles' => '...'];)
$system_messages = require 'system_messages.php';
if (!is_array($system_messages)) {
    gp_model_write_debug_log("CRITICAL: System messages configuration is invalid in system_messages.php.");
     // You might allow fallback defaults here instead of exiting, depending on requirements
     header('Content-Type: application/json; charset=UTF-8');
     echo json_encode(['status' => 'error', 'error' => 'Server configuration error [System Messages].']);
     exit();
}


// --- Rate Limiting Configuration ---
$rate_limiting_enabled = true;
$rate_limits = [
    // Action name => ['limit' => X requests, 'window' => Y seconds]
    // --- MODIFIED: Rate limit applies primarily to USER initiated chats ---
    'chat_messages'   => ['limit' => 50, 'window' => 3600], // Includes start_conversation/chat initiated by USER
    'create_program'  => ['limit' => 15, 'window' => 3600], // File creation/modification
    'clear_conversation' => ['limit' => 5, 'window' => 3600], // Limit clearing frequency
];

// --- Session Initialization ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Initialize session variables if they don't exist
if (!isset($_SESSION['gperson_system_messages'])) {
    $_SESSION['gperson_system_messages'] = $system_messages;
}
if (!isset($_SESSION['gperson_conversation'])) {
    $_SESSION['gperson_conversation'] = [];
}

// --- MODIFIED: Enhanced Initialization/Update for gperson_settings ---
$default_model_settings = [
    'slot_models' => [
        'Ava'     => 'gemini-2.0-flash-lite',
        'Gala'    => 'gemini-2.0-flash',
        'Charles' => 'gemini-2.5-flash-preview-04-17',
        'Program' => 'gemini-2.5-pro-exp-03-25'
    ],
    'max_tokens' => 65536,
    'temperature' => 0.6,
    'turns' => 6
];

if (!isset($_SESSION['gperson_settings'])) {
    $_SESSION['gperson_settings'] = $default_model_settings;
    gp_model_write_debug_log("Initialized NEW gperson_settings in session.");
} else {
    $update_needed = false;
    if (!isset($_SESSION['gperson_settings']['slot_models']) || !is_array($_SESSION['gperson_settings']['slot_models'])) {
        $_SESSION['gperson_settings']['slot_models'] = $default_model_settings['slot_models'];
        $update_needed = true;
        gp_model_write_debug_log("Added/Replaced missing or invalid 'slot_models' key in existing gperson_settings.");
    } else {
        foreach ($default_model_settings['slot_models'] as $slot => $default_model) {
            if (!isset($_SESSION['gperson_settings']['slot_models'][$slot])) {
                 $_SESSION['gperson_settings']['slot_models'][$slot] = $default_model;
                 $update_needed = true;
                 gp_model_write_debug_log("Added missing '{$slot}' model to gperson_settings['slot_models'].");
            }
        }
    }
    foreach ($default_model_settings as $key => $value) {
        if ($key !== 'slot_models' && !isset($_SESSION['gperson_settings'][$key])) {
             $_SESSION['gperson_settings'][$key] = $value;
             $update_needed = true;
              gp_model_write_debug_log("Added missing top-level setting '{$key}' to gperson_settings.");
        }
    }
    if ($update_needed) {
        gp_model_write_debug_log("Updated existing gperson_settings structure in session.");
    }
}
// --- End Enhanced Initialization ---

// --- MODIFIED: Engagement timer with Retries ---

$default_engagement_timer = [
    'deadline'   => null,
    'interval'   => 45,
    'multiplier' => 1.6,
    'engagement_retries' => 0,
    'max_engagement_retries' => 5
];

if (!isset($_SESSION['engagement_timer'])) {
    $_SESSION['engagement_timer'] = $default_engagement_timer;
    gp_model_write_debug_log("Initialized NEW engagement_timer in session.");
} else {
    $updated_timer = false;
    foreach ($default_engagement_timer as $key => $defaultValue) {
        if (!isset($_SESSION['engagement_timer'][$key])) {
            $_SESSION['engagement_timer'][$key] = $defaultValue;
            $updated_timer = true;
            gp_model_write_debug_log("Added missing key '{$key}' to engagement_timer.");
        }
    }
    if ($updated_timer) {
        gp_model_write_debug_log("Updated existing engagement_timer structure.");
    }
}

// Initialize rate limiting counters
if ($rate_limiting_enabled && !isset($_SESSION['rate_limiting'])) {
    $_SESSION['rate_limiting'] = [];
    foreach (array_keys($rate_limits) as $action_key) {
        $_SESSION['rate_limiting'][$action_key] = ['count' => 0, 'start_time' => time()];
    }
}
// Initialize memories
if (!isset($_SESSION['memories'])) {
     $_SESSION['memories'] = ['user_info' => [], 'engagement_strategies' => [], 'general' => []];
}

// --- Utility Functions ---
function gp_model_write_debug_log($message) {
    static $in_logging = false;
    if ($in_logging) return;
    $in_logging = true;
    try {
        $log_file = __DIR__ . '/gp_model_debug.log';
        $date = date('Y-m-d H:i:s');
        if (!is_scalar($message)) {
            $message = print_r($message, true);
        }
        if (strlen($message) > 4000) {
            $message = substr($message, 0, 3997) . '...';
        }
        error_log("[$date] $message\n", 3, $log_file);
    } catch (Exception $e) {
        error_log("[$date] Logging failed: " . $e->getMessage(), 0);
    } finally {
        $in_logging = false;
    }
}

function verify_recaptcha($token, $action, $threshold = 0.5) {
    global $recaptcha_config;
    if (empty($token)) {
        gp_model_write_debug_log("reCAPTCHA: Skipping verification due to empty token (assuming internal call or test). Action: {$action}");
        return true;
    }
    $secret = $recaptcha_config['secret_key'];
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = ['secret' => $secret, 'response' => $token];
    $options = ['http' => [
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query($data),
        'timeout' => 10
    ]];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    if ($result === FALSE) {
        gp_model_write_debug_log("reCAPTCHA Error: Unable to contact Google verification server.");
        return false;
    }
    $result_json = json_decode($result, true);
    if (!$result_json) {
        gp_model_write_debug_log("reCAPTCHA Error: Failed to decode response: " . $result);
        return false;
    }
    if (
        isset($result_json['success']) && $result_json['success'] &&
        isset($result_json['score'], $result_json['action']) &&
        $result_json['action'] === $action &&
        $result_json['score'] >= $threshold
    ) {
        gp_model_write_debug_log("reCAPTCHA OK. Score: {$result_json['score']}, Action: {$result_json['action']} (Expected: {$action})");
        return true;
    } else {
        $error_codes = isset($result_json['error-codes']) ? implode(', ', $result_json['error-codes']) : 'N/A';
        gp_model_write_debug_log("reCAPTCHA Failed. Success: " . ($result_json['success'] ?? 'N/A') . " Score: " . ($result_json['score'] ?? 'N/A') . " Action: " . ($result_json['action'] ?? 'N/A') . " Expected Action: {$action}. Errors: " . $error_codes);
        return false;
    }
}

function check_rate_limit($action) {
    global $rate_limiting_enabled, $rate_limits;
    if (!$rate_limiting_enabled || !isset($rate_limits[$action])) {
        return true;
    }
    if (!isset($_SESSION['rate_limiting'])) {
        $_SESSION['rate_limiting'] = [];
         foreach (array_keys($GLOBALS['rate_limits']) as $action_key) {
            $_SESSION['rate_limiting'][$action_key] = ['count' => 0, 'start_time' => time()];
         }
         gp_model_write_debug_log("Re-initialized rate_limiting structure in session during check.");
    }
    if (!isset($_SESSION['rate_limiting'][$action])) {
        $_SESSION['rate_limiting'][$action] = ['count' => 0, 'start_time' => time()];
        gp_model_write_debug_log("Initialized rate_limiting for specific action '{$action}' during check.");
    }
    $limit_info = $rate_limits[$action];
    $limit = $limit_info['limit'];
    $window = $limit_info['window'];
    $current_time = time();
    if (($current_time - $_SESSION['rate_limiting'][$action]['start_time']) > $window) {
        gp_model_write_debug_log("Resetting rate limit window for action '{$action}'.");
        $_SESSION['rate_limiting'][$action]['count'] = 0;
        $_SESSION['rate_limiting'][$action]['start_time'] = $current_time;
    }
    if ($_SESSION['rate_limiting'][$action]['count'] >= $limit) {
        gp_model_write_debug_log("Rate limit exceeded for action '{$action}'. Count: {$_SESSION['rate_limiting'][$action]['count']}, Limit: {$limit}/{$window}s.");
        return false;
    }
    $_SESSION['rate_limiting'][$action]['count']++;
     gp_model_write_debug_log("Rate limit incremented for action '{$action}'. New count: {$_SESSION['rate_limiting'][$action]['count']}/{$limit}.");
    return true;
}

function sanitize_code_path($user_path) {
    $base = realpath(CODE_BASE_DIR);
    if ($base === false || !is_dir($base)) {
        gp_model_write_debug_log("CRITICAL ERROR: Base code directory " . CODE_BASE_DIR . " is not valid or accessible.");
        return false;
    }
    $user_path = str_replace('\\', '/', $user_path);
    $user_path = trim($user_path, '/ ');
    if (strpos($user_path, '..') !== false || substr($user_path, 0, 1) === '/') {
        gp_model_write_debug_log("Path validation failed: Contains '..' or is absolute. Path: " . $user_path);
        return false;
    }
    $full_path = $base . DIRECTORY_SEPARATOR . $user_path;
    $dir_path = dirname($full_path);
    $real_dir_path = realpath($dir_path);
    if (strpos($full_path, $base . DIRECTORY_SEPARATOR) !== 0 && $full_path !== $base && $dir_path !== $base) {
         gp_model_write_debug_log("Path validation failed: Intended path is outside base directory structure. Path: " . $full_path . ", Base: " . $base);
         return false;
    }
    if ($real_dir_path !== false) {
        if (strpos($real_dir_path, $base) !== 0) {
             gp_model_write_debug_log("Path validation failed: Resolved directory path is outside base directory. Real Dir: " . $real_dir_path . ", Base: " . $base);
             return false;
        }
        return $real_dir_path . DIRECTORY_SEPARATOR . basename($full_path);
    } else {
        gp_model_write_debug_log("Path validation: Directory {$dir_path} doesn't exist, but intended path {$full_path} is within base {$base}. Allowing for creation.");
        return $full_path;
    }
}

/**
 * Makes an API call to the Google Gemini API (single-shot or with retry/fallback).
 * - `$max_retries` : number of automatic retries on *benign* empty replies.
 * - `$fallback_model` : model to try once if the primary model keeps returning empty.
 */
function google_gemini_api_call(
    $model,
    $messages,
    $max_tokens        = 65536,
    $temperature       = 0.6,
    $system_instruction = null,
    $max_retries       = 1,
    $fallback_model    = null
) {
    global $geminiApiKey;
    if (empty($model) || !is_array($messages)) {
        gp_model_write_debug_log("Error in google_gemini_api_call: Invalid model ('$model') or messages format.");
        return ['status' => 'error', 'error' => 'Internal setup error.', 'details' => 'Invalid model or messages format.'];
    }
    gp_model_write_debug_log("Making Gemini API call. Model: $model, Max Tokens: $max_tokens, Temp: $temperature, Messages count: " . count($messages));
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$geminiApiKey}";
    $headers = ['Content-Type: application/json'];

    /* ------------------------------------------------------------------ *
     * 1) Format messages for the Gemini API
     * ------------------------------------------------------------------ */
    $formattedMessages = [];
    foreach ($messages as $msg) {
        if (!isset($msg['content']) || $msg['content'] === '' || !isset($msg['role']) || !is_scalar($msg['content'])) {
            gp_model_write_debug_log("Skipping malformed or empty message in API call preparation: " . print_r($msg, true));
            continue;
        }
        $role = ($msg['role'] === 'assistant' || $msg['role'] === 'model') ? 'model' : 'user';
        if ($role !== 'user' && $role !== 'model') {
            gp_model_write_debug_log("Adjusting invalid role '{$msg['role']}' to 'user' for API call.");
            $role = 'user';
        }
        $formattedMessages[] = ['role' => $role,
                                'parts' => [['text' => $msg['content']]]];
    }

    /* 1a) Inject a dummy user prompt if the last turn is not user-role.
     *     Gemini will sometimes stay silent if it thinks it already spoke. */
    if (!empty($formattedMessages) && end($formattedMessages)['role'] !== 'user') {
        $formattedMessages[] = [
            'role'  => 'user',
            'parts' => [['text' => '(continue)']]
        ];
    }

    // Ensure we still have messages after filtering
    if (empty($formattedMessages)) {
        gp_model_write_debug_log("Error in google_gemini_api_call: No valid messages to send to API.");
        return ['status' => 'error','error' => 'Internal setup error.','details'=>'No messages'];
    }

    $data = [
        "contents" => $formattedMessages,
        "generationConfig" => [
            "temperature" => (float)$temperature,
            "maxOutputTokens" => (int)$max_tokens,
        ]
    ];
    if ($system_instruction && is_scalar($system_instruction) && trim($system_instruction) !== '') {
        $data["systemInstruction"] = ["parts" => [["text" => trim($system_instruction)]]];
        gp_model_write_debug_log("Using System Instruction for API call.");
    } else {
        gp_model_write_debug_log("No System Instruction provided or it was empty/invalid.");
    }
    $json_payload = json_encode($data);
    if ($json_payload === false) {
        gp_model_write_debug_log("CRITICAL ERROR: Failed to encode API payload. JSON Error: " . json_last_error_msg());
        return ['status' => 'error', 'error' => 'Internal server error.', 'details' => 'Failed to create API request payload.'];
    }

    /* ------------------------------------------------------------------ *
     * 2) Execute request    → automatic retry on *silent empty* replies
     * ------------------------------------------------------------------ */
    $attempt = 0;
    RETRY_REQUEST:
    $attempt++;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => $json_payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response   = curl_exec($ch);
    $httpcode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        gp_model_write_debug_log("Curl error in google_gemini_api_call: " . $curl_error);
        return ['status'=>'error','error'=>'API communication error (cURL).','details'=>$curl_error];
    }
    $decoded = json_decode($response, true);

    // ← NEW: if the API itself returned an error object, handle it immediately
    if (isset($decoded['error'])) {
    gp_model_write_debug_log(
      "API ERROR: Code={$decoded['error']['code']} Msg={$decoded['error']['message']}"
    );
    return [
      'status'  => 'error',
      'error'   => "API Error: {$decoded['error']['message']}",
      'details' => $decoded['error'],
        ];
    }
    
    if ($httpcode !== 200 || json_last_error() !== JSON_ERROR_NONE) {
        gp_model_write_debug_log("HTTP {$httpcode}, JSON error ".json_last_error().". Raw:\n{$response}");
        return ['status'=>'error','error'=>'API connection or JSON decoding error.','details'=>['http_code'=>$httpcode,'json_error'=>json_last_error(),'raw_response'=>substr($response,0,1000)]];
    }
    $cand = $decoded['candidates'][0] ?? null;
    $finishReason = $cand['finishReason'] ?? 'UNKNOWN';
    gp_model_write_debug_log("FinishReason: {$finishReason}");
    if (in_array($finishReason, ['BLOCKLIST','SAFETY','RECITATION'])) {
        gp_model_write_debug_log("Content blocked by policy. FinishReason={$finishReason}");
        return ['status'=>'error','error'=>"Content blocked ({$finishReason})","details"=>$cand];
    }
    if ($finishReason !== 'STOP' && $finishReason !== 'MAX_TOKENS') {
        gp_model_write_debug_log("API call finished unexpectedly. Reason: {$finishReason}");
        $error_msg = 'API call finished unexpectedly';
        if ($finishReason==='SAFETY') $error_msg='Response blocked by API safety filters.';
        elseif($finishReason==='RECITATION') $error_msg='Response blocked due to recitation.';
        if (!empty($cand['content']['parts'][0]['text'])) {
            gp_model_write_debug_log("Returning partial content due to finish reason: {$finishReason}");
            return ['status'=>'success','content'=>$cand['content']['parts'][0]['text']];
        } else {
            return ['status'=>'error','error'=>$error_msg,'details'=>$cand];
        }
    }
    if (isset($cand['content']['parts'][0]['text']) && trim($cand['content']['parts'][0]['text'])!=='') {
        return ['status'=>'success','content'=>$cand['content']['parts'][0]['text']];
    }
    if ($finishReason==='STOP' && $attempt<=$max_retries) {
        gp_model_write_debug_log("Empty STOP; retrying {$attempt}/{$max_retries}");
        sleep(1); goto RETRY_REQUEST;
    }
    if ($fallback_model && $model!==$fallback_model) {
        gp_model_write_debug_log("Empty after retries – falling back to {$fallback_model}");
        return google_gemini_api_call($fallback_model,$messages,$max_tokens,$temperature,$system_instruction,0,null);
    }
    if (in_array($finishReason,['STOP','MAX_TOKENS'])) {
        gp_model_write_debug_log("Final empty response after {$attempt} attempt(s).");
        return ['status'=>'success','content'=>''];
    }
    gp_model_write_debug_log("Unexpected candidate structure or missing text content:\n".json_encode($cand,JSON_PRETTY_PRINT));
    return ['status'=>'error','error'=>'API returned unexpected response content structure.','details'=>$decoded];
}


// --- Memory Management Functions ---

/**
 * Loads memories from the session. (Persistence logic removed for focus, add back if needed)
 */
function load_memories($session_id) {
    // Simple session-based memory for this example
    return $_SESSION['memories'] ?? ['user_info' => [], 'engagement_strategies' => [], 'general' => []];
}

/**
 * Processes content for memory tags (if from Ava) and cleans them.
 */
function handle_memory($content, $model_slot_name, $record_memories = true, $user_id_for_search = null) {
    if (preg_match('/<search_keywords>(.*?)<\/search_keywords>/is', $content, $search_matches)) {
             $keywords_string = trim($search_matches[1]);
             if (!empty($keywords_string)) {
                 $keywords_array = array_map('trim', explode(',', $keywords_string));
                 $keywords_array = array_filter($keywords_array); // Remove empty keywords
             }
               
        } 
        
    gp_model_write_debug_log("Entering handle_memory - Model Slot: " . $model_slot_name . ", UserID for LTM Search: " . ($user_id_for_search ?? 'N/A'));

    // Initialize/clear retrieved long-term memories for this turn if Ava is processing
    if ($model_slot_name === 'Ava') {
        $_SESSION['retrieved_long_term_memories'] = [];
    }

    if ($model_slot_name === 'Ava') {
        // 1) Process <search_keywords> tags (NEW)
        if (preg_match('/<search_keywords>(.*?)<\/search_keywords>/is', $content, $search_matches)) {
            $keywords_string = trim($search_matches[1]);
            if (!empty($keywords_string)) {
                $keywords_array = array_map('trim', explode(',', $keywords_string));
                $keywords_array = array_filter($keywords_array); // Remove empty keywords

                if (!empty($keywords_array)) {
                    gp_model_write_debug_log("Extracted search keywords from Ava: " . implode(', ', $keywords_array));
            // Use last user message as semantic query text
            $last_user_msg = end($_SESSION['gperson_conversation']);
            $query_text = ($last_user_msg['role'] ?? '') === 'user'
                         ? $last_user_msg['content']
                         : null;
            $retrieved_memories = search_long_term_memories(
                $keywords_array,
                $user_id_for_search,
                5,
                session_id(),
                $query_text
            );
            if (!empty($retrieved_memories)) {
                $_SESSION['retrieved_long_term_memories'] = $retrieved_memories;
                gp_model_write_debug_log("Retrieved " . count($retrieved_memories) . " long-term memories.");
            } else {
                gp_model_write_debug_log("No long-term memories found for keywords: " . implode(', ', $keywords_array));
            }
                } else {
                    gp_model_write_debug_log("No valid keywords extracted from <search_keywords> tag.");
                }
            } else {
                 gp_model_write_debug_log("Empty <search_keywords> tag found.");
            }
            // Strip the <search_keywords> tag
            $content = preg_replace('/<search_keywords>.*?<\/search_keywords>/is', '', $content);
            gp_model_write_debug_log("Processed and removed <search_keywords> tag from Ava's response.");
        } else {
            gp_model_write_debug_log("No <search_keywords> tag found in Ava's response.");
        }

        // 2) Process <memory> tags (for short-term session memory)
        if ($record_memories && preg_match_all('/<memory>(.*?)<\/memory>/is', $content, $matches)) {
            foreach ($matches[1] as $memory_raw) {
                $memory_raw = trim($memory_raw);
                if ($memory_raw === '') continue;
                if (preg_match('/^(user_info|engagement_strategies):\d+:(.+)$/s', $memory_raw, $m)) {
                    store_memory($m[1], trim($m[2])); // store_memory is for session file memory
                } else {
                    store_memory('general', $memory_raw);
                }
            }
            $content = preg_replace('/<memory>.*?<\/memory>/is', '', $content);
            gp_model_write_debug_log("Processed and removed short-term memory tags from Ava's response.");
        }

        // 3) Process <engage> tags (for short-term session memory)
        if ($record_memories && preg_match_all('/<engage>(.*?)<\/engage>/is', $content, $mEng)) {
            foreach ($mEng[1] as $eRaw) {
                $txt = trim($eRaw);
                if ($txt !== '') {
                    store_memory('engagement_strategies', $txt);
                }
            }
            $content = preg_replace('/<engage>.*?<\/engage>/is', '', $content);
            gp_model_write_debug_log("Captured ".count($mEng[1])." <engage> memories from Ava.");
        }
    }
    return trim($content);
}


/* Return the most recent <engage> line Ava gave us, or null */
function get_latest_engagement_message() {
    if (empty($_SESSION['memories']['engagement_strategies'])) {
        return null;
    }
    $all = $_SESSION['memories']['engagement_strategies'];
    return trim(end($all)) ?: null;          // newest, whitespace trimmed
}

/**
 * Stores a memory item on server.
 */
function store_memory($type, $memory) {
    // gp_model_write_debug_log("Entering store_memory - Type: " . $type); // Avoid logging memory content

    if (!isset($_SESSION['memories'])) {
        $_SESSION['memories'] = ['user_info' => [], 'engagement_strategies' => [], 'general' => []];
    }
    if (!isset($_SESSION['memories'][$type])) {
        $_SESSION['memories'][$type] = []; // Initialize type if not present
    }

    // Simple append, consider limits or better indexing if needed
     $nextIndex = count($_SESSION['memories'][$type]) + 1;
     $_SESSION['memories'][$type][$nextIndex] = $memory; // Using index as key

    // gp_model_write_debug_log("Memory stored - Type: " . $type . ", Index: " . $nextIndex);
    // gp_model_write_debug_log("Current memory keys: " . json_encode(array_keys($_SESSION['memories']))); // Log keys instead of full content

    // Save to persistent storage
    $session_id = session_id();
    if (!$session_id) {
        gp_model_write_debug_log("Error storing memory: Invalid session ID.");
        return;
    }
    $memory_dir = __DIR__ . "/memories";
    if (!is_dir($memory_dir)) {
         @mkdir($memory_dir, 0775, true); // Suppress errors if dir already exists between check and creation
    }
    $memory_file = $memory_dir . "/memory_{$session_id}.json";
    if (@file_put_contents($memory_file, json_encode($_SESSION['memories'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
         gp_model_write_debug_log("Error writing memory file: " . $memory_file);
    }
}

// --- Main Request Handling ---

// Set JSON Header for all responses
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Get and decode JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate JSON input
if (json_last_error() !== JSON_ERROR_NONE) {
    gp_model_write_debug_log("Invalid JSON input received: " . json_last_error_msg() . " | Input: " . $input);
    echo json_encode(['status' => 'error', 'error' => 'Invalid request format (JSON).']);
    exit();
}

// Extract action and reCAPTCHA token
$action = isset($data['action']) ? trim($data['action']) : null;
$recaptcha_token = isset($data['recaptcha']) ? trim($data['recaptcha']) : '';

// --- NEW: Flag to track if the current action is internally triggered by heartbeat ---
$is_internal_heartbeat_trigger = false;

// --- Action Router ---
switch ($action) {
    case 'create_program':
        // 1. Rate Limiting (Specific to this action)
        if (!check_rate_limit('create_program')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded for file operations.']);
            exit();
        }

        // 2. reCAPTCHA Verification (Use a specific action name)
        if (empty($recaptcha_token) || !verify_recaptcha($recaptcha_token, 'create_program')) {
            echo json_encode(['success' => false, 'message' => 'reCAPTCHA verification failed.']);
            exit();
        }

        // (engagement timer reset is now handled in chat only)

        // 3. Input Validation
        // ... (rest of validation as before)
        $user_path = isset($data['filePath']) ? trim($data['filePath']) : '';
        $instructions = isset($data['instructions']) ? trim($data['instructions']) : '';
        $showCodeInChat = isset($data['showCodeInChat']) && $data['showCodeInChat'];
        $use_history = isset($data['use_history']) && $data['use_history'];

        // Basic validation checks
        if (empty($user_path)) { echo json_encode(['success' => false, 'message' => 'File path is required.']); exit(); }
        if (empty($instructions)) { echo json_encode(['success' => false, 'message' => 'Instructions/code are required.']); exit(); }
        if (strlen($user_path) > 255) { echo json_encode(['success' => false, 'message' => 'File path too long (max 255).']); exit(); }
        if (strlen($instructions) > 20000) { echo json_encode(['success' => false, 'message' => 'Instructions too long (max 20000).']); exit(); } // Increased limit

        // 4. Path Sanitization (CRITICAL)
        $full_path = sanitize_code_path($user_path);
        if ($full_path === false) {
            echo json_encode(['success' => false, 'message' => 'Invalid or insecure file path specified.']);
            exit();
        }
        gp_model_write_debug_log("Action 'create_program': Path validated to: " . $full_path);

        // 5. Prepare Prompt for Charles (Coding Assistant)
        // ... (rest of prompt preparation as before)
        $messages_for_charles = [];
        $charles_system_prompt = $_SESSION['gperson_system_messages']['Charles'] ?? 'You are Charles, a helpful coding assistant.'; // Fallback
        // --- MODIFIED: Get the dedicated model for program creation ---
        $program_creation_model = $_SESSION['gperson_settings']['slot_models']['Program'] ?? 'gemini-1.5-pro-latest'; // Fallback model

        $current_content = null;
        $action_description = "create a new file";
        if (file_exists($full_path)) {
            $current_content = @file_get_contents($full_path);
            if ($current_content === false) {
                 gp_model_write_debug_log("Warning: File exists but could not be read: " . $full_path);
                 $current_content = null; // Treat as new file if unreadable
                 $action_description = "create a new file (existing file unreadable)";
            } else {
                $action_description = "modify the existing file";
                gp_model_write_debug_log("File exists, reading content. Length: " . strlen($current_content));
                 // Truncate very large existing content before sending to API
                 if (strlen($current_content) > 50000) {
                    $current_content = substr($current_content, 0, 50000) . "\n\n... [Existing content truncated for API context] ...";
                    gp_model_write_debug_log("Truncated existing content sent to API.");
                 }
            }
        }

        // Build conversation history context if requested
        $history_context = "";
        if ($use_history && !empty($_SESSION['gperson_conversation'])) {
            $history_context .= "Relevant conversation history:\n";
            $history_chars = 0;
            $max_hist_chars = 10000; // Limit history context size
            foreach (array_reverse($_SESSION['gperson_conversation']) as $msg) {
                 // *** FILTER OUT OLD ERRORS FROM HISTORY ***
                 if (isset($msg['content']) && strpos(trim($msg['content']), '[Error:') === 0) {
                     continue; // Skip error messages
                 }
                 if (in_array($msg['role'], ['user', 'assistant', 'model'])) { // Include model role
                    $role_label = $msg['role'] === 'user' ? 'user' : ($msg['model'] ?? 'assistant'); // Use model slot name if available
                    $line = "{$role_label}: {$msg['content']}\n";
                    if ($history_chars + strlen($line) > $max_hist_chars) break;
                    $history_context = $line . $history_context; // Prepend to keep order
                    $history_chars += strlen($line);
                 }
            }
             if ($history_chars > 0 && $history_chars >= $max_hist_chars) {
                 $history_context = "... [History truncated] ...\n" . $history_context;
             }
             $history_context .= "\n---\n";
        }

        // Construct the final prompt for the coding model
        $user_request_prompt = "Task: {$action_description} named `{$user_path}` located within the designated code directory.\n\n";
        if ($current_content !== null) {
            $user_request_prompt .= "Current file content:\n```\n{$current_content}\n```\n\n";
        }
        $user_request_prompt .= "User instructions/requirements:\n{$instructions}\n\n";
        $user_request_prompt .= $history_context; // Append filtered history context
        $user_request_prompt .= "IMPORTANT: Respond ONLY with the complete, updated code for the file `{$user_path}`. Do NOT include any explanations, commentary, apologies, or markdown formatting (like ```language ... ```) outside the code itself. If the request is unclear or cannot be fulfilled, respond with a single line starting with 'Error:' followed by a brief explanation.";

        // Add the user request to the messages array for the API
        $messages_for_charles[] = ['role' => 'user', 'content' => $user_request_prompt];


        // 6. Call Gemini API (using the dedicated program creation model)
        // ... (rest of API call as before)
        $api_response = google_gemini_api_call(
            $program_creation_model,
            $messages_for_charles,
            $_SESSION['gperson_settings']['max_tokens'],
            $_SESSION['gperson_settings']['temperature'],
            $charles_system_prompt // Pass Charles's system prompt separately
        );


        // 7. Process API Response
        // ... (rest of API response processing as before)
         if ($api_response['status'] !== 'success') {
            $error_msg = $api_response['error'] ?? 'Failed to get code from AI model.';
            gp_model_write_debug_log("API call failed for 'create_program' using model '{$program_creation_model}': " . $error_msg . " | Details: " . ($api_response['details'] ? json_encode($api_response['details']) : 'N/A'));
            // *** DO NOT ADD TO SESSION HISTORY ***
            echo json_encode(['success' => false, 'message' => "AI Error: " . $error_msg]); // Return error directly
            exit();
        }

        $generated_code = $api_response['content'];

        // Check if the model responded with an explicit error message
        if (strpos(trim($generated_code), 'Error:') === 0) {
            $error_msg = trim($generated_code);
            gp_model_write_debug_log("Model '{$program_creation_model}' responded with error for 'create_program': " . $error_msg);
            // *** DO NOT ADD TO SESSION HISTORY ***
             echo json_encode(['success' => false, 'message' => $error_msg]); // Return model's error directly
            exit();
        }

        // Clean up potential markdown (though prompt asks not to include it)
        $generated_code = preg_replace('/^```[a-zA-Z]*\s*\n?/', '', $generated_code);
        $generated_code = preg_replace('/\n?```$/', '', $generated_code);
        $generated_code = trim($generated_code);

        gp_model_write_debug_log("Generated code length: " . strlen($generated_code));


        // 8. Write File (Create directories if needed)
        // ... (rest of file writing as before)
        $dir_path = dirname($full_path);
        if (!is_dir($dir_path)) {
            if (!@mkdir($dir_path, 0775, true)) {
                $error = error_get_last();
                $error_msg = "Failed to create directory: " . ($error['message'] ?? 'Unknown error');
                gp_model_write_debug_log($error_msg . " Path: " . $dir_path);
                // *** DO NOT ADD TO SESSION HISTORY ***
                echo json_encode(['success' => false, 'message' => "Server Error: " . $error_msg]); // Return server error directly
                exit();
            }
             gp_model_write_debug_log("Created directory: " . $dir_path);
        }

        if (@file_put_contents($full_path, $generated_code) === false) {
            $error = error_get_last();
            $error_msg = "Failed to write file: " . ($error['message'] ?? 'Unknown error');
            gp_model_write_debug_log($error_msg . " Path: " . $full_path);
            // *** DO NOT ADD TO SESSION HISTORY ***
            echo json_encode(['success' => false, 'message' => "Server Error: " . $error_msg]); // Return server error directly
            exit();
        }


        // 9. Success Response
        // ... (rest of success response as before)
         $success_msg = "File '{$user_path}' " . ($current_content !== null ? "updated" : "created") . " successfully.";
        gp_model_write_debug_log($success_msg);

        // Add a *clean* confirmation message to the conversation history
        $confirmation_message = "Okay, I have " . ($current_content !== null ? "updated" : "created") . " the file `{$user_path}`.";
        // Assign confirmation message to Charles, even though a different model might have generated the code
        $_SESSION['gperson_conversation'][] = ['role' => 'assistant', 'model' => 'Charles', 'content' => $confirmation_message];

        // If showing code in chat, add it as a separate message
        if ($showCodeInChat) {
            $_SESSION['gperson_conversation'][] = ['role' => 'assistant', 'model' => 'Charles', 'content' => "```\n" . $generated_code . "\n```"];
        }

        echo json_encode([
            'success' => true,
            'message' => $success_msg,
            'confirmation_message' => $confirmation_message, // For potential frontend use
            'content' => $showCodeInChat ? $generated_code : null, // Send content only if requested
            // Send updated history so frontend reflects the confirmation/code
            'conversation' => $_SESSION['gperson_conversation']
        ]);
        break;

// ---------------------------------------------------------------------------
//  MODIFIED ACTION – heartbeat
// ---------------------------------------------------------------------------

case 'heartbeat':
    // gp_model_write_debug_log("→ Received heartbeat request for session " . session_id());
    $timer = $_SESSION['engagement_timer'] ?? null;
    // … rest of your heartbeat logic …

    // Nothing overdue, or timer not set up properly
    if (!$timer || !$timer['deadline'] || time() < $timer['deadline']) {
        // gp_model_write_debug_log("Heartbeat: No deadline or deadline not reached.");
        echo json_encode(['status' => 'waiting']);
        break;
    }

    /* ---- Stop heartbeat after 20 min of silence OR after max retries ---- */
    $silent_cutoff = 1200;   // 20 min
    $no_user_for   = time() - ($_SESSION['engagement_timer']['last_user_ts'] ?? time());
    if (
        $timer['engagement_retries'] >= $timer['max_engagement_retries'] ||
        $no_user_for >= $silent_cutoff
    ) {
        // log and mark it stopped
        gp_model_write_debug_log(
            "Heartbeat stopped (retries={$timer['engagement_retries']}, idle={$no_user_for}s)."
        );
        // prevent any further re-initialization
        $_SESSION['engagement_timer']['stopped'] = true;
        $_SESSION['engagement_timer']['deadline'] = null;   // fully disable
        echo json_encode(['status' => 'silent']);          // frontend can ignore
        break;
    }
    // --- End New ---

    // Deadline missed – re‑queue Gala for one turn
    gp_model_write_debug_log("Heartbeat: Deadline missed. Retries: {$timer['engagement_retries']}/{$timer['max_engagement_retries']}. Triggering Gala re-engagement.");

    // --- NEW: Increment retry counter ---
    $_SESSION['engagement_timer']['engagement_retries']++;

    // --- NEW: Set flag indicating this is an internal trigger ---
    $is_internal_heartbeat_trigger = true;
    
    // We want Gala not Ava, to handle the engagement line
    $available_slots = ['Gala'];   // <-- replace old ['Gala']

    $data = [
        'action'    => 'chat',
        'prompt'    => '',   // Invisible user turn
        'turns'     => 1,    // Only Gala should respond
        'recaptcha' => '',   // No token for internal action
        // Keep memory settings from session or default them? Assume defaults work for re-engagement
        'record_memories' => true, // Allow Gala to potentially record simple memories if needed?
        'use_memories' => true,    // Allow Gala to use context
    ];
    $action = 'chat';      // fall‑through to chat handler (no break)
    // --- Fallthrough ---


    case 'start_conversation': // Handles the main chat input
    case 'chat': // Merged logic

        // --- MODIFIED: Conditional Rate Limiting ---
        // Apply rate limit ONLY if it's NOT an internal heartbeat trigger
        if (!$is_internal_heartbeat_trigger) {
             if (!check_rate_limit('chat_messages')) {
                  echo json_encode(['status' => 'error', 'error' => 'Rate limit exceeded for chat messages.']);
                  exit();
             }
        } else {
             gp_model_write_debug_log("Skipping chat_messages rate limit check for internal heartbeat trigger.");
        }
        // --- End Modification ---

        // --- MODIFIED: Conditional reCAPTCHA Verification ---
        // Skip reCAPTCHA if it's an internal trigger (token should be empty anyway)
        if (!$is_internal_heartbeat_trigger) {
            $recaptcha_action_name = ($action === 'start_conversation') ? 'start_conversation' : 'chat'; // Or just use 'chat' always?
             if (empty($recaptcha_token) || !verify_recaptcha($recaptcha_token, $recaptcha_action_name)) {
                 echo json_encode(['status' => 'error', 'error' => 'reCAPTCHA verification failed.']);
                 exit();
             }
        } else {
            gp_model_write_debug_log("Skipping reCAPTCHA check for internal heartbeat trigger.");
        }
        // --- End Modification ---


        // 3. Input Validation
        if (!isset($data['prompt']) || !isset($data['turns'])) {
            echo json_encode(['status' => 'error', 'error' => 'Incomplete chat data (prompt or turns missing).']);
            exit();
        }

        $prompt = trim($data['prompt']);
        $turns = max(1, intval($data['turns']));
        gp_model_write_debug_log("User prompt received: " . $prompt);
        $record_memories = isset($data['record_memories']) ? (bool)$data['record_memories'] : true; // Default true
        $use_memories = isset($data['use_memories']) ? (bool)$data['use_memories'] : true; // Default true

        // Check if the prompt is empty *after* trim, *unless* this is the internal heartbeat trigger
        if (empty($prompt) && !$is_internal_heartbeat_trigger) {
             echo json_encode(['status' => 'error', 'error' => 'Prompt cannot be empty.']); exit();
        }
        if (strlen($prompt) > 50000) { echo json_encode(['status' => 'error', 'error' => 'Prompt too long (max 50000).']); exit(); }

        // --- MODIFIED: Reset engagement timer/retries on USER interaction ---
        // Check if it's NOT an internal trigger AND the prompt is not empty (real user message)
        if (!$is_internal_heartbeat_trigger && !empty($prompt) && isset($_SESSION['engagement_timer'])) {
            $_SESSION['engagement_timer']['deadline']   = null;
            $_SESSION['engagement_timer']['interval']   = 45;
            $_SESSION['engagement_timer']['engagement_retries'] = 0;
            $_SESSION['engagement_timer']['last_user_ts'] = time();
            gp_model_write_debug_log("User action '{$action}': Reset engagement timer.");
        }
        // --- End Modification ---


        // Clean potential memory tags from raw user input (if user somehow adds them)
        $clean_prompt = preg_replace('/<memory>\s*.*?\s*<\/memory>/si', '', $prompt);
        // Add the user's prompt to the conversation history *only if it's not an empty heartbeat trigger*
        if (!$is_internal_heartbeat_trigger && !empty($clean_prompt)) {
            $_SESSION['gperson_conversation'][] = ['role' => 'user', 'content' => $clean_prompt];
        }


        gp_model_write_debug_log("Action '{$action}'" . ($is_internal_heartbeat_trigger ? " (Internal Trigger)" : "") . ". Prompt len: " . strlen($clean_prompt) . ". Turns: {$turns}. UseMem: " . ($use_memories ? 'Y' : 'N') . ". RecordMem: " . ($record_memories ? 'Y' : 'N'));

        // 4. Multi-Turn Model Interaction Logic
        $available_slots = ['Ava', 'Gala']; // Default interaction flow

        // --- MODIFIED: Adjust available slots based on trigger type ---
        if ($is_internal_heartbeat_trigger) {
             $available_slots = ['Gala']; // ONLY Gala responds to the heartbeat trigger
             $turns = 1; // Ensure only one turn happens for the heartbeat
             gp_model_write_debug_log("Heartbeat trigger: Processing only Gala for 1 turn.");
        } else {
            // Original logic for regular chat/start_conversation actions initiated by user
            $is_coding_request = (stripos($prompt, 'charles') !== false || preg_match('/\b(code|script|program|function|class|file|directory)\b/i', $prompt));
             if ($is_coding_request) {
                 // Add Charles, but decide order. Maybe Ava -> Gala -> Charles? Or let Gala/Ava handle first?
                 // Let's add to the end for now.
                 if (!in_array('Charles', $available_slots)) {
                     $available_slots[] = 'Charles';
                 }
                 gp_model_write_debug_log("Potential coding request detected, adding Charles to queue end.");
             }
             // Adjust turns based on session setting if needed, or use input $turns
             $turns = min($turns, $_SESSION['gperson_settings']['turns'] ?? 6); // Use the lower of requested or max allowed
        }
        // --- End Modification ---


        // Load current memories if enabled
        $session_memories = $use_memories ? load_memories(session_id()) : [];

        // --- Loop through models for this turn ---
        for ($i = 0; $i < $turns && !empty($available_slots); $i++) {
            $current_slot_name = array_shift($available_slots); // Get the next model in the queue

            // --- MODIFIED: Get the specific model API name for the current slot ---
            if (!isset($_SESSION['gperson_settings']['slot_models'][$current_slot_name])) {
                gp_model_write_debug_log("ERROR: Model configuration missing for slot '{$current_slot_name}'. Skipping turn.");
                continue; // Skip this slot if its model is not defined
            }
            $current_model_api_name = $_SESSION['gperson_settings']['slot_models'][$current_slot_name];
            // --- End Modification ---

            $current_system_prompt = $_SESSION['gperson_system_messages'][$current_slot_name] ?? ''; // Get specific system prompt

            gp_model_write_debug_log("--- Processing turn #".($i+1)." for: {$current_slot_name} using model: {$current_model_api_name} ---");

            // --- Prepare messages for this specific model ---
            $messages_for_slot = [];
            // Only include memory & history for non-Ava slots
            if ($current_slot_name !== 'Ava') {
                // --- Combined Memory Context Block (Short-Term and Long-Term) ---
                 $memory_context_content = "";
                 // Add short-term session memories...
            if ($use_memories && !empty(array_filter($session_memories))) {
                $memory_context_content .= "Short-Term Session Memories:\n";
                foreach ($session_memories as $type => $memory_list) {
                    if (!empty($memory_list)) {
                        $memory_context_content .= "  [$type]:\n";
                        $mem_count = 0;
                        foreach ($memory_list as $number => $memory_item_content) {
                            if (!is_scalar($memory_item_content)) continue;
                            if ($mem_count++ >= 5) { // Limit short-term memories per type
                                $memory_context_content .= "    - ... (more truncated)\n";
                                break;
                            }
                            $memory_context_content .= "    - ($number) " . substr($memory_item_content, 0, 200) . (strlen($memory_item_content) > 200 ? '...' : '') . "\n";
                        }
                    }
                }
                $memory_context_content .= "\n"; // Add a newline after short-term memories
            }

            // Add retrieved long-term memories (especially for Gala, but available if session var is set)
            if (isset($_SESSION['retrieved_long_term_memories']) && !empty($_SESSION['retrieved_long_term_memories'])) {
                $memory_context_content .= "Retrieved Long-Term Memories (Consider these relevant to the current query):\n";
                $ltm_count = 0;
                foreach ($_SESSION['retrieved_long_term_memories'] as $ltm_item) {
                    if ($ltm_count++ >= 3) { // Limit displayed LTMs to top 3 for brevity in context
                        $memory_context_content .= "  - ... (more LTMs retrieved but truncated for context)\n";
                        break;
                    }
                    $memory_context_content .= "  - Memory ID: " . ($ltm_item['id'] ?? 'N/A') . "\n";
                    $memory_context_content .= "    Content: " . substr($ltm_item['memory_content'] ?? 'N/A', 0, 300) . (strlen($ltm_item['memory_content'] ?? '') > 300 ? '...' : '') . "\n";
                    if (!empty($ltm_item['keywords_ai'])) {
                        $memory_context_content .= "    Keywords: " . substr($ltm_item['keywords_ai'], 0, 100) . (strlen($ltm_item['keywords_ai']) > 100 ? '...' : '') . "\n";
                    }
                    $memory_context_content .= "\n";
                }
                 $memory_context_content .= "\n"; // Add a newline after long-term memories
            }

            // If any memory context was built, add it as a user message
            if (!empty(trim($memory_context_content))) {
                $full_memory_context_message = "Background Information & Memories (Use this context to inform your response):\n" . $memory_context_content;
                $messages_for_slot[] = ['role' => 'user', 'content' => $full_memory_context_message];
                gp_model_write_debug_log("Added combined memory context block as 'user' message for $current_slot_name. Context length: " . strlen($full_memory_context_message));
            }
            // --- End Combined Memory Context Block ---
            }
            // Add recent, filtered conversation history
            if ($current_slot_name !== 'Ava') {
               // Add recent, filtered conversation history
                 $history_token_count = 0;
                 $limited_history = [];
                 
            $history_token_count = 0;
            $limited_history = [];
            $max_history_chars = 30000; // Max characters for history context
            $message_count = 0;
            $max_messages = 30; // Limit number of past messages

            // Filter and limit history
            $filtered_history = [];
            foreach($_SESSION['gperson_conversation'] as $msg) {
                 // --- MODIFIED: Stricter filtering ---
                 if (
                     !isset($msg['role']) || !isset($msg['content']) || // Must have role and content
                     !is_scalar($msg['content']) || trim($msg['content']) === '' || // Content must be non-empty scalar
                     strpos(trim($msg['content']), '[Error:') === 0 || // Skip error messages
                     !in_array($msg['role'], ['user', 'assistant', 'model']) // Only include these roles
                 ) {
                     continue;
                 }
                 // Assign a 'model' name if missing for assistant/model roles
                 if (($msg['role'] === 'assistant' || $msg['role'] === 'model') && empty($msg['model'])) {
                     $msg['model'] = 'assistant'; // Default name if missing
                 }
                 $filtered_history[] = $msg;
            }

            // Add messages from filtered history, newest first, respecting limits
            foreach (array_reverse($filtered_history) as $hist_msg) {
                 if ($message_count >= $max_messages) break; // Limit message count

                 $msg_len = strlen($hist_msg['content'] ?? '');
                 if (($history_token_count + $msg_len) > $max_history_chars) {
                      gp_model_write_debug_log("History character limit reached for {$current_slot_name}. Truncating.");
                     break; // Stop adding older messages
                 }
                 $limited_history[] = $hist_msg; // Add to temp array (reversed order)
                 $history_token_count += $msg_len;
                 $message_count++;
            }

             // Add the limited history in the correct chronological order to the API messages
             foreach (array_reverse($limited_history) as $hist_msg_to_add) {
                  $messages_for_slot[] = $hist_msg_to_add;
             }
             gp_model_write_debug_log("Added " . count($limited_history) . " messages from history for {$current_slot_name}. Char count: {$history_token_count}");
            }
            
            // For Ava, strip out all prior context—only send the current user prompt
            if ($current_slot_name === 'Ava') {
                $messages_for_slot = [
                    ['role' => 'user', 'content' => $clean_prompt]
                ];
                gp_model_write_debug_log("Ava receives only current user prompt; skipped memory & history.");
            }
/* ---------- NEW: give Gala a user cue when heartbeat fired ---------- */
if ($prompt === '') {   // internal heartbeat
    $engage = get_latest_engagement_message();
    if (!$engage) {
        // fallback if Ava never supplied one
        $engage = "System: The user hasn't responded yet – please try a more engaging response.";
    }
    $messages_for_slot[] = [
        'role'    => 'user',
        'content' => $engage
    ];
    
    gp_model_write_debug_log('Injected engage-prompt for Gala: ' .
                              substr($engage,0,120));
}


            // … up above, after you finish assembling $messages_for_slot …

// 1) Ensure last prompt is a user turn, so Gemini always speaks:
if (! empty($messages_for_slot)
    && end($messages_for_slot)['role'] !== 'user'
) {
    $messages_for_slot[] = [
        'role'    => 'user',
        'content' => '(please respond)',
    ];
    gp_model_write_debug_log(
        "Injected dummy user prompt for {$current_slot_name}"
    );
}

// 2) Call the API, with 1 retry on empty STOP and a Flash fallback:
$api_response = google_gemini_api_call(
    $current_model_api_name,                    // your chosen slot model
    $messages_for_slot,                         // full message array
    $_SESSION['gperson_settings']['max_tokens'],
    $_SESSION['gperson_settings']['temperature'],
    $current_system_prompt,                     // slot’s system instruction
    1,                                          // max_retries = 1
    'gemini-2.0-flash'                          // fallback_model
);

// … then your existing logic to handle $api_response …

            // --- Process API Response for this model ---
            if ($api_response['status'] === 'success') {
                $assistant_reply = $api_response['content'];

                 if (!empty($assistant_reply)) {
                      // Process memory tags (primarily for Ava) and clean the reply
                      $processed_reply = handle_memory($assistant_reply, $current_slot_name, $record_memories);

                      if (!empty($processed_reply)) {
                           $_SESSION['gperson_conversation'][] = [
                               'role' => 'assistant', // Use 'assistant' for consistency in history
                               'model' => $current_slot_name, // Store the SLOT name (Ava, Gala, Charles)
                               'content' => $processed_reply
                           ];
                           gp_model_write_debug_log("{$current_slot_name} (Model: {$current_model_api_name}) responded. Length: " . strlen($processed_reply));

if ($is_internal_heartbeat_trigger && $current_slot_name === 'Gala') {
    // pretend Gala is the engagement point when Ava isn’t in the loop
    if (isset($_SESSION['engagement_timer'])) {
        $t =& $_SESSION['engagement_timer'];
        $t['deadline']  = time() + $t['interval'];
        $t['interval']  = min(ceil($t['interval'] * $t['multiplier']), 43200);
        gp_model_write_debug_log("Heartbeat re-engage: set new deadline " .
                                 date('Y-m-d H:i:s', $t['deadline']));
    }
}
                           // --- MODIFIED: Interaction Chaining Logic & LTM Packaging ---

                           // LTM Packaging by Charles after Gala's response (if not heartbeat)
                           if ($current_slot_name === 'Gala' && !$is_internal_heartbeat_trigger && !empty($processed_reply)) {
                               gp_model_write_debug_log("Attempting LTM Packaging with Charles after Gala's response.");
                               $ltm_user_id = session_id(); // Use session_id as user_id for now
                               $ltm_interaction_id = session_id() . '_' . time(); // Unique interaction ID

                               // Prepare conversation snippet for Charles
                               $last_user_prompt_content = 'User prompt not found.';
                               $gala_response_content = $processed_reply;

                               // Find the last user message in history for context
                               $reversed_history = array_reverse($_SESSION['gperson_conversation']);
                               foreach ($reversed_history as $hist_msg) {
                                   if ($hist_msg['role'] === 'user' && !empty($hist_msg['content'])) {
                                       $last_user_prompt_content = $hist_msg['content'];
                                       break;
                                   }
                               }

                               $charles_ltm_prompt = "Charles, please package the following interaction for long-term memory:\nUser: " . $last_user_prompt_content . "\nGala: " . $gala_response_content;
                               
                               $charles_model_api_name = $_SESSION['gperson_settings']['slot_models']['Charles'] ?? 'gemini-1.5-pro-latest'; // Default if not set
                               $charles_system_prompt_ltm = $_SESSION['gperson_system_messages']['Charles'] ?? '';

                               $messages_for_charles_ltm = [
                                   ['role' => 'user', 'content' => $charles_ltm_prompt]
                               ];

                               gp_model_write_debug_log("Calling Charles for LTM packaging. Model: {$charles_model_api_name}");

                               $charles_api_response = google_gemini_api_call(
                                   $charles_model_api_name,
                                   $messages_for_charles_ltm,
                                   $_SESSION['gperson_settings']['max_tokens'],
                                   $_SESSION['gperson_settings']['temperature'],
                                   $charles_system_prompt_ltm,
                                   1, // max_retries
                                   'gemini-1.0-pro' // fallback_model, ensure it's a valid one if 1.5 flash is not available or suitable
                               );

                               if ($charles_api_response['status'] === 'success' && !empty($charles_api_response['content'])) {
                                   $charles_ltm_output = $charles_api_response['content'];
                                   gp_model_write_debug_log("Charles LTM Packaging Response: " . substr($charles_ltm_output, 0, 500) . (strlen($charles_ltm_output) > 500 ? '...' : ''));

                                   // Parse Charles's LTM package
                                   if (preg_match('/<ltm_package>(.*?)<\/ltm_package>/is', $charles_ltm_output, $package_match)) {
                                       $package_content = $package_match[1];
                                       $memory_content_ltm = '';
                                       $keywords_ai_ltm = '';
                                       $context_notes_ltm = null;
                                       $related_keywords_ltm = null;

                                       if (preg_match('/<memory_content>(.*?)<\/memory_content>/is', $package_content, $mc_match)) {
                                           $memory_content_ltm = trim($mc_match[1]);
                                       }
                                       if (preg_match('/<keywords_ai>(.*?)<\/keywords_ai>/is', $package_content, $ka_match)) {
                                           $keywords_ai_ltm = trim($ka_match[1]);
                                       }
                                       if (preg_match('/<context_notes>(.*?)<\/context_notes>/is', $package_content, $cn_match)) {
                                           $context_notes_ltm = trim($cn_match[1]);
                                       }
                                       if (preg_match('/<related_keywords>(.*?)<\/related_keywords>/is', $package_content, $rk_match)) {
                                           $related_keywords_ltm = trim($rk_match[1]);
                                       }

                                       if (!empty($memory_content_ltm) && !empty($keywords_ai_ltm)) {
                                           $stored_ltm_id = store_long_term_memory(
                                               $memory_content_ltm,
                                               $ltm_user_id,
                                               $ltm_interaction_id,
                                               $keywords_ai_ltm,
                                               null, // keywords_prompt (Ava handles this for search, Charles provides AI keywords for storage)
                                               $context_notes_ltm,
                                               'Charles_LTM_Packager',
                                               $related_keywords_ltm
                                           );
                                           if ($stored_ltm_id) {
                                               gp_model_write_debug_log("Successfully stored LTM package from Charles. ID: " . $stored_ltm_id);
                                           } else {
                                               gp_model_write_debug_log("Failed to store LTM package from Charles in DB.");
                                           }
                                       } else {
                                           gp_model_write_debug_log("Charles LTM package parsing failed: memory_content or keywords_ai missing.");
                                       }
                                   } else {
                                       gp_model_write_debug_log("Charles LTM package tag not found in response: " . $charles_ltm_output);
                                   }
                               } else {
                                   $error_msg_charles = $charles_api_response['error'] ?? 'Charles LTM packaging failed to respond or returned empty.';
                                   gp_model_write_debug_log("Charles LTM packaging API call failed: " . $error_msg_charles);
                               }
                           }

                           // ➊ Queue Ava immediately after Gala IF it was a USER turn (not heartbeat)

                           // ➊ Queue Ava immediately after Gala IF it was a USER turn (not heartbeat)
                         //if ($current_slot_name === 'Gala' && !$is_internal_heartbeat_trigger) {
                             //if (!in_array('Ava', $available_slots, true)) {
                                   // Insert Ava at the beginning of the remaining queue
                                 //array_unshift($available_slots, 'Ava');
                                 //gp_model_write_debug_log('Auto‑queued Ava to respond immediately after Gala.');
                             //}
                         //}
                            // ➊ (REMOVED) — no longer auto-queue Ava after Gala

                           // ➋ If Gala mentioned Charles (and not internal trigger), queue Charles after Ava (if present) or at the end
                            if ($current_slot_name === 'Gala' && !$is_internal_heartbeat_trigger && stripos($processed_reply, 'charles') !== false) {
                                if (!in_array('Charles', $available_slots, true)) {
                                    $ava_index = array_search('Ava', $available_slots);
                                    if ($ava_index !== false) {
                                        // Insert Charles right after Ava
                                        array_splice($available_slots, $ava_index + 1, 0, 'Charles');
                                        gp_model_write_debug_log('Gala mentioned Charles, inserted Charles after Ava in queue.');
                                    } else {
                                        // Ava not queued (or already processed), add Charles to the end
                                        $available_slots[] = 'Charles';
                                        gp_model_write_debug_log('Gala mentioned Charles, queued Charles at end of turn.');
                                    }
                                } else {
                                     gp_model_write_debug_log('Gala mentioned Charles, but Charles was already in the queue.');
                                }
                            }


                           // ➌ When Ava speaks, (re)start the response‑deadline timer (regardless of trigger)
                           if ($current_slot_name === 'Gala') {
                               if (isset($_SESSION['engagement_timer'])) {
                                   $t =& $_SESSION['engagement_timer'];
                                   $t['deadline'] = time() + $t['interval'];   // next user deadline
                                   $t['interval']  = min(ceil($t['interval'] * $t['multiplier']), 43200); // cap growth (12 h), ensure integer
                                   // Don't reset retries here, only on user action
                                   gp_model_write_debug_log("Gala spoke: Set engagement deadline to " . date('Y-m-d H:i:s', $t['deadline']) . ", next interval: {$t['interval']}s.");
                               }
                           }
                           // --- End Interaction Chaining ---

                      } else {
                           gp_model_write_debug_log("{$current_slot_name} (Model: {$current_model_api_name}) provided an empty (but successful) response after memory handling. Not adding to history.");
                      }
                 } else {
                      gp_model_write_debug_log("{$current_slot_name} (Model: {$current_model_api_name}) API call successful but returned empty content. Not adding to history.");
                 }

            } else {
                $error_msg = $api_response['error'] ?? "{$current_slot_name} failed to respond.";
                gp_model_write_debug_log(
                    "API call failed for {$current_slot_name} (Model: {$current_model_api_name}): {$error_msg}"
                    // Limit details logging
                    . " | Details: " . (isset($api_response['details']) ? substr(json_encode($api_response['details']), 0, 200) . '...' : 'N/A')
                );
                // Do NOT add errors to the main chat history visible to the user.
                // Optionally, add a generic error to the history?
                // $_SESSION['gperson_conversation'][] = ['role' => 'system', 'content' => "[Error: {$current_slot_name} encountered an issue.]"];
            }
        } // --- End of model loop for this turn ---


        // 5. Return the final conversation state to the frontend
        echo json_encode([
  'status'       => $is_internal_heartbeat_trigger ? 'reengaged' : 'success',
  'conversation' => $_SESSION['gperson_conversation']
]);
        break;


    case 'clear_conversation':
         // 1. Rate Limiting
         if (!check_rate_limit('clear_conversation')) {
             echo json_encode(['status' => 'error', 'error' => 'Rate limit exceeded for clearing conversation.']);
             exit();
         }

         // 2. reCAPTCHA Verification
         if (empty($recaptcha_token) || !verify_recaptcha($recaptcha_token, 'clear_conversation')) {
            echo json_encode(['status' => 'error', 'error' => 'reCAPTCHA verification failed.']);
            exit();
         }

       // (engagement timer reset is now handled in chat only)

        // 3. Clear Session Data
        $_SESSION['gperson_conversation'] = [];
        // $_SESSION['memories'] = ['user_info' => [], 'engagement_strategies' => [], 'general' => []]; // Reset memories too
        // Forget the IDs we were suppressing – next chat segment starts clean
        $_SESSION['ltm_saved_this_segment'] = [];
        // Optionally clear persistent memory file if used
        // $session_id = session_id();
        // if ($session_id) {
        //    $memory_file = __DIR__ . "/memories/memory_{$session_id}.json";
        //    if (file_exists($memory_file)) {
        //        @unlink($memory_file);
        //    }
        //}

        gp_model_write_debug_log("Conversation cleared by user request. Session: " . session_id());
        echo json_encode(['status' => 'success', 'message' => 'Conversation cleared.']);
        break;

    default:
        gp_model_write_debug_log("Unknown action received: '{$action}'");
        echo json_encode(['status' => 'error', 'error' => 'Unknown action specified.']);
        break;
}

// Ensure session data is written before script ends
session_write_close();

?>
