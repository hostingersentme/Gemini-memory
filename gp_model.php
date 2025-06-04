<?php
session_start();

// **Add Charset Headers**
header('Content-Type: text/html; charset=UTF-8'); // Ensure UTF-8 encoding
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Error Reporting
ini_set('display_errors', 1); // Prevent errors from being displayed to users
ini_set('log_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('error_log', '/home/u212406014/domains/informationism.org/public_html/Gemini_memory/gp_model_debug.log'); // Renamed debug file
error_reporting(E_ALL); // Report all errors

// Function to write to multi-slot debug log
function gp_model_write_debug_log($message) { // Renamed function
    $log_file = __DIR__ . '/gp_model_debug.log'; // Renamed debug file
    $date = date('Y-m-d H:i:s');
    $full_message = "[$date] $message\n";

    if (file_put_contents($log_file, $full_message, FILE_APPEND) === false) {
        error_log("Failed to write to gp_model_debug.log");
    }
}

// Include the configuration file
//Here



// Include the secure API key file
require 'api_key.php';

// Include the centralized system messages
$system_messages = require 'system_messages.php'; // Will be updated later

// Initialize conversation history
if (!isset($_SESSION['gperson_conversation'])) { // Renamed session variable
    $_SESSION['gperson_conversation'] = [];
}

// Define system messages as fixed (non-editable)
if (!isset($_SESSION['gperson_system_messages'])) { // Renamed session variable
    $_SESSION['gperson_system_messages'] = $system_messages;
}

// Initialize settings
if (!isset($_SESSION['gperson_settings'])) { // Renamed session variable
    $_SESSION['gperson_settings'] = [
        'model' => 'gemini-2.0-flash', // Updated default model
        'max_tokens' => 65536, // Increased tokens for code
        'temperature' => 0.6, // Adjusted temperature for coding
        'turns' => 6 // Keep turns or adjust as needed
    ];
}

// Retrieve reCAPTCHA site key
$config = require 'recaptcha_config.php';
$site_key = htmlspecialchars($config['site_key']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>i Gemini Person & Code Generator</title> <!-- Changed Title -->
    <link rel="stylesheet" href="imodel_styles.css">
    <!-- Load reCAPTCHA v3 -->
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo $site_key; ?>" async defer></script>
    <style>
        /* Inline styles for the dropdown arrow rotation */
        .triangle {
            transition: transform 0.3s;
        }
        .triangle.open {
            transform: rotate(180deg);
        }

        /* Styles for floating text from Ava (Removed for simplicity) */
        /* Removed the previous floating text styles to simplify */

        /* Optional: Adjusting message styling if needed */

        .program-options, .memories-options { /* Renamed class */
            margin: 10px 0;
        }
        .program-options label, .memories-options label { /* Renamed class */
            margin-left: 5px;
            color: #555;
            vertical-align: middle;
        }

        /* Additional styling for new textareas */
        #programInstructions { /* Renamed ID */
            width: 100%;
            height: 150px; /* Increased height */
            margin-bottom: 10px;
        }
        #filePath { /* New ID */
             width: 100%;
             margin-bottom: 10px;
        }
        .charles-message { /* Style for Charles's code output */
             background-color: #e0e0e0;
             border-left: 5px solid #555;
             font-family: monospace;
             white-space: pre-wrap; /* Preserve whitespace and wrap */
             word-wrap: break-word;
        }
    
    /* Heartbeat pulsing animation */
    #heartbeatIndicator {
        position: fixed;
        bottom: 20px;
        left: 20px;           /* you’d moved it to the left */
        z-index: 10000;       /* sit above the reCAPTCHA badge */
        pointer-events: auto; /* make sure clicks go through */
        font-size: 2rem;
        cursor: pointer;
    user-select: none;
    }
    @keyframes pulse {
        0%   { transform: scale(1); }
        50%  { transform: scale(1.2); }
        100% { transform: scale(1); }
    }
    .pulsing {
        animation: pulse 1s infinite;
    }
    .stopped {
        animation: none !important;  /* force-stop */
        opacity: 0.5;
    }
    </style>
</head>
<body>

<!-- Heartbeat Indicator -->
    <div id="heartbeatIndicator" class="pulsing">❤️</div>

<!-- Top Menu placed at the top -->
<div class="top-menu">
    <div>
        <a href="https://informationism.org/index.php">Home</a>
        <a href="https://informationism.org/gp_model.php">The i Model (Code)</a> <!-- Updated Link Text -->
        <a href="https://informationism.org/GoogleAI/g_model.php">i model with Wiki</a>
        <a href="https://informationism.org/botmeet/index.php/Main_Page">The Robot’s Guide to Humanity wiki</a>
    </div>
    <div>
        <button id="loginButton">Log In/Register</button>
    </div>
</div>

<div class="container">
    <!-- Emoji Container within the main content container -->
    <div class="content-container">
        <div id="emojiContainer"></div>

        <!-- Title Box -->
        <div class="title-box">
            <h1 style="color: #6c63ff;">i Model Gemini Person & Code Generator</h1> <!-- Changed Title -->
        </div>

        <!-- Multi Step Interaction -->
        <div>
            <h2 style="color: #6c63ff;">The Personal AI Coder</h2> <!-- Changed Subtitle -->
            <label for="initialPrompt">What's up?:</label>
            <textarea id="initialPrompt" placeholder="Enter your prompt or coding request for Charles (e.g., 'Charles, create a python file named app.py that prints hello world')" maxlength="5000"></textarea>
            <div class="char-counter" id="promptCounter">0/5000 characters</div>

            <!-- No reCAPTCHA widget needed for v3 -->
            <button id="startConversationButton">Send</button> <!-- Renamed ID for clarity -->
            <button id="clearConversationButton">Clear</button> <!-- Renamed ID for clarity -->
        </div>

        <!-- Conversation History -->
        <div id="conversationContainer"> <!-- Renamed ID -->
            <?php
            // Display existing conversation from session
            if (isset($_SESSION['gperson_conversation'])) {
                foreach ($_SESSION['gperson_conversation'] as $msg) {
                    if ($msg['role'] === 'system') {
                        continue;
                    } elseif ($msg['role'] === 'user') {
                        echo '<div class="message"><strong>User:</strong> ' . nl2br(htmlspecialchars($msg['content'])) . '</div>';
                    } elseif ($msg['role'] === 'assistant') {
                        $model = htmlspecialchars($msg['model'] ?? 'Unknown');
                        // Apply specific style for Charles
                        $messageClass = $model === 'Charles' ? 'charles-message' : 'ai-message';
                        echo '<div class="message ' . $messageClass . '"><strong>AI (' . $model . '):</strong> ' . nl2br(htmlspecialchars($msg['content'])) . '</div>';
                    }
                }
            }
            ?>
        </div>

         <!-- Code File Creation Section -->
        <div>
            <h2 style="color: #6c63ff;">Create/Modify Program File</h2> <!-- Changed Title -->

            <!-- File Path Input -->
            <label for="filePath">File Path (relative to code_files/):</label> <!-- Changed Label -->
            <textarea id="filePath" placeholder="e.g., 'my_script.py' or 'project_folder/main.js'" maxlength="255"></textarea> <!-- Changed ID and Placeholder -->
            <div class="char-counter" id="filePathCounter">0/255 characters</div> <!-- Changed ID -->

            <!-- Instructions Input -->
            <label for="programInstructions">Instructions/Code:</label> <!-- Changed Label -->
            <textarea id="programInstructions" placeholder="Enter instructions, code requirements, or the code itself..." maxlength="10000"></textarea> <!-- Changed ID, Placeholder, Increased Maxlength -->
            <div class="char-counter" id="programInstructionsCounter">0/10000 characters</div> <!-- Changed ID -->

            <button id="createProgramButton">Create/Update File</button> <!-- Changed ID and Text -->
            <div id="programStatus"></div> <!-- Changed ID -->

            <!-- Program Options -->
            <div class="program-options"> <!-- Renamed class -->
                 <label><input type="checkbox" name="use_history" checked> Use conversation history for context</label> <!-- Keep this -->
                 <label><input type="checkbox" id="showCodeInChat" name="showCodeInChat"> Show generated code in chat</label> <!-- New option -->
            </div>

            <!-- Memories Options (Keep as is) -->
            <div class="memories-options">
                <input type="checkbox" id="recordMemories" name="recordMemories" checked>
                <label for="recordMemories" style="display: inline; margin-left: 5px;">Record Memories</label>

                <input type="checkbox" id="useMemories" name="useMemories" checked style="margin-left: 20px;">
                <label for="useMemories" style="display: inline; margin-left: 5px;">Use Memories</label>
            </div>
        </div>

    </div>

    <!-- Social Media Links -->
    <div class="social-media">
        <a href="https://x.com/RadicalEconomic" target="_blank">Twitter</a>
        <a href="https://www.reddit.com/user/rutan668/" target="_blank">Reddit</a>
        <a href="https://github.com/hostingersentme" target="_blank">Github</a>
    </div>

    <!-- User Info -->
    <div class="user-info">
        <p>User info: This is an AI model featuring three personalities: 'Ava', 'Gala', and 'Charles'. Ava handles emotions and memories, Gala is the general conversationalist, and **Charles is the coding assistant.**
        Usage: Use the top box for general chat or specific coding requests for Charles (e.g., "Charles, create a file `app.py`..."). The "Create/Modify Program File" section allows you to provide a file path and detailed instructions or code for Charles to write to the `code_files` directory on the server.
        Give feedback at the social media links. If you encounter issues, try clearing the session. Long sessions might confuse the models.</p> <!-- Updated Text -->
    </div>

    <!-- Rate Limit Info -->
    <div class="rate-limit-info" id="rateLimitInfo" style="color: #ff0000; margin-top: 20px;">
        <!-- Rate limit messages will appear here -->
    </div>
</div>

<script>

// Define the emoji to color mapping
const emojiColorMap = {
  // 😊 🙂 😄 😃 😁 😂 🤣 — happiness/laughter
  '😊': '#FFD700',
  '🙂': '#FFD700',
  '😄': '#FFD700',
  '😃': '#FFD700',
  '😁': '#FFD700',
  '😂': '#FFD700',
  '🤣': '#FFD700',

  // 😢 😟 😫 😞 😪 — sadness/worry/exhaustion
  '😢': '#1E90FF',
  '😟': '#1E90FF',
  '😫': '#1E90FF',
  '😞': '#1E90FF',
  '😪': '#1E90FF',

  // 😠 😡 😤 — anger/frustration
  '😠': '#FF4500',
  '😡': '#FF4500',
  '😤': '#FF4500',

  // ❤️ 💖 😍 💕 — love/affection
  '❤️': '#FF0000',
  '💖': '#FF0000',
  '😍': '#FF69B4',
  '💕': '#FF69B4',

  // 🤔 ❓ ⁉️ 😕 — thinking/question/confusion
  '🤔': '#8A2BE2',
  '❓': '#8A2BE2',
  '⁉️': '#8A2BE2',
  '😕': '#8A2BE2',

  // 😴 😮‍💨 — sleepiness/relief
  '😴': '#808080',
  '😮‍💨': '#808080',

  // 😮 😲 — surprise
  '😮': '#8A2BE2',
  '😲': '#8A2BE2'
};

function escapeHtml(text) {
    const map = {
        '&': '&',
        '<': '<',
        '>': '>',
        '"': '"',
        "'": "'"
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}


// Login Button (Keep as is)
document.getElementById('loginButton')?.addEventListener('click', () => {
    window.location.href = 'https://informationism.org/register.php';
});

// Character counters
const initialPrompt = document.getElementById('initialPrompt');
const promptCounter = document.getElementById('promptCounter');
initialPrompt.addEventListener('input', () => {
    promptCounter.textContent = `${initialPrompt.value.length}/5000 characters`;
});

const filePath = document.getElementById('filePath'); // Updated ID
const filePathCounter = document.getElementById('filePathCounter'); // Updated ID
filePath.addEventListener('input', () => {
    filePathCounter.textContent = `${filePath.value.length}/255 characters`;
});

const programInstructions = document.getElementById('programInstructions'); // Updated ID
const programInstructionsCounter = document.getElementById('programInstructionsCounter'); // Updated ID
programInstructions.addEventListener('input', () => {
    programInstructionsCounter.textContent = `${programInstructions.value.length}/10000 characters`;
});

// Function to get system messages for API call
function getSystemMessages() {
  const systemMessages = <?php echo json_encode($_SESSION['gperson_system_messages'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>; // Renamed session variable
    return systemMessages;
}

// Function to extract emojis from text
function extractEmojis(text) {
    // Ensure text is a string before matching
    if (typeof text !== 'string') {
         console.warn("extractEmojis received non-string:", text);
         return []; // Return empty array if not a string
    }
    const emojiRegex = /([\u2700-\u27BF]|[\uE000-\uF8FF]|[\uD83C-\uDBFF][\uDC00-\uDFFF])/g;
    return text.match(emojiRegex) || []; // Return empty array if no match
}


// Function to display emojis on screen and change background color
function displayEmojis(emojis) {
    const emojiContainer = document.getElementById('emojiContainer');
    emojiContainer.innerHTML = ''; // Clear previous emojis

    let colorScores = {}; // To tally emotion scores

    // Get container dimensions
    const containerWidth = emojiContainer.offsetWidth;
    const containerHeight = emojiContainer.offsetHeight;

    emojis.forEach(emoji => {
        const emojiElement = document.createElement('span');
        emojiElement.textContent = emoji;
        emojiElement.classList.add('floating-emoji');

        // Random size between 20px and 50px
        const size = Math.floor(Math.random() * 30) + 20;
        emojiElement.style.fontSize = `${size}px`;

        // Random position within the emoji container
        const x = Math.random() * (containerWidth - size);
        const y = Math.random() * (containerHeight - size);

        emojiElement.style.left = `${x}px`;
        emojiElement.style.top = `${y}px`;

        // Random animation duration between 3s and 7s
        const duration = Math.random() * 4 + 3;
        emojiElement.style.animationDuration = `${duration}s`;

        emojiContainer.appendChild(emojiElement);

        // Map emoji to color
        if (emojiColorMap[emoji]) {
            const color = emojiColorMap[emoji];
            colorScores[color] = (colorScores[color] || 0) + 1;
        }
    });

    // Determine the most frequent color
    let dominantColor = null;
    let maxScore = 0;
    for (const color in colorScores) {
        if (colorScores[color] > maxScore) {
            maxScore = colorScores[color];
            dominantColor = color;
        }
    }

    // Change background color if a dominant color is found
    if (dominantColor) {
        document.body.style.backgroundColor = dominantColor;
    } else {
        // Reset to default if no dominant color
        document.body.style.backgroundColor = '#f5f7fa';
    }
}

// Function to update conversation display
function updateConversationDisplay(role, content, model = null) { // Modified slightly
    const conversationContainer = document.getElementById('conversationContainer'); // Renamed ID
    const messageDiv = document.createElement('div');
    messageDiv.className = 'message';

    let innerHTML = '';
    if (role === 'user') {
        innerHTML = `<strong>User:</strong> ${escapeHtml(content).replace(/\n/g, '<br>')}`;
    } else if (role === 'assistant') {
        const displayModel = escapeHtml(model || 'AI');
        const messageClass = displayModel === 'Charles' ? 'charles-message' : 'ai-message';
        messageDiv.classList.add(messageClass);
        // Basic code block detection for highlighting (simple approach)
        let formattedContent = escapeHtml(content).replace(/\n/g, '<br>');
         if (displayModel === 'Charles' && formattedContent.includes('<code>') && formattedContent.includes('</code>')) {
             // Optional: Add more sophisticated syntax highlighting here later if needed
             formattedContent = formattedContent.replace(/<code>([\s\S]*?)<\/code>/g, '<pre><code style="display: block; background-color: #f0f0f0; padding: 10px; border-radius: 4px;">$1</code></pre>');
         } else if (displayModel === 'Charles' && formattedContent.includes('```')) {
             formattedContent = formattedContent.replace(/```([\s\S]*?)```/g, '<pre><code style="display: block; background-color: #f0f0f0; padding: 10px; border-radius: 4px;">$1</code></pre>');
         }
        innerHTML = `<strong>AI (${displayModel}):</strong> ${formattedContent}`;
    } else if (role === 'system') { // Added system message display for confirmations etc.
         messageDiv.classList.add('system-message');
         innerHTML = `<em>System: ${escapeHtml(content).replace(/\n/g, '<br>')}</em>`;
    } else if (role === 'error') { // Added error message display
        messageDiv.classList.add('error-message');
        innerHTML = `<strong>Error:</strong> ${escapeHtml(content).replace(/\n/g, '<br>')}`;
    }


    messageDiv.innerHTML = innerHTML;
    conversationContainer.appendChild(messageDiv);
    conversationContainer.scrollTop = conversationContainer.scrollHeight; // Scroll to bottom
}

function refreshConversation(convArray) {
  const conversationContainer = document.getElementById('conversationContainer');
  const emojiContainer       = document.getElementById('emojiContainer');

  // clear out any old messages/emojis
  conversationContainer.innerHTML = '';
  emojiContainer.innerHTML = '';

  convArray.forEach(msg => {
    if (msg.role === 'assistant') {
      if (msg.model === 'Ava') {
        // pull any emojis out of Ava’s response
        const emojis = extractEmojis(msg.content);
        if (emojis.length) displayEmojis(emojis);

        // strip the emojis from the text before showing it
        const text = msg.content
                       .replace(/([\u2700-\u27BF]|[\uE000-\uF8FF]|[\uD83C-\uDBFF][\uDC00-\uDFFF])/g,'')
                       .trim();
        if (text) updateConversationDisplay('assistant', text, msg.model);
      } else {
        // non-Ava replies just get dumped straight in
        updateConversationDisplay('assistant', msg.content, msg.model);
      }
    } else if (msg.role === 'user') {
      // your own messages
      updateConversationDisplay('user', msg.content);
    }
  });
}


// Function to display rate limit info
function displayRateLimitInfo(message) {
    const rateLimitInfo = document.getElementById('rateLimitInfo');
    if (rateLimitInfo) {
        rateLimitInfo.textContent = message;
    }
}

// Start Conversation (Main chat button)
document.getElementById('startConversationButton').addEventListener('click', async () => { // Renamed ID
    let prompt = initialPrompt.value.trim();
    const conversationContainer = document.getElementById('conversationContainer'); // Renamed ID
    const emojiContainer = document.getElementById('emojiContainer');

    if (!prompt) {
        alert('Please enter an initial prompt or coding request.');
        return;
    }

    if (prompt.length > 5000) {
        alert('Prompt exceeds the maximum allowed length of 5000 characters.');
        return;
    }

    // Get memories settings
    const recordMemories = document.getElementById('recordMemories').checked;
    const useMemories = document.getElementById('useMemories').checked;

    // Display user prompt immediately
    updateConversationDisplay('user', prompt);

    // Disable buttons while processing
    document.getElementById('startConversationButton').disabled = true;
    document.getElementById('clearConversationButton').disabled = true;
    document.getElementById('startConversationButton').textContent = 'Processing...';
    document.getElementById('startConversationButton').classList.add('loading');

    try {
        const systemMessages = getSystemMessages();
        const token = await grecaptcha.execute('<?php echo $site_key; ?>', {action: 'start_conversation'});

        const response = await fetch('gp_model_api_updated.php', { // Renamed API file
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'start_conversation', // Action remains similar for chat flow
                prompt: prompt,
                turns: <?php echo json_encode($_SESSION['gperson_settings']['turns']); ?>, // Renamed session var
                system_messages: systemMessages,
                record_memories: recordMemories,
                use_memories: useMemories,
                recaptcha: token
            })
        });

        if (!response.ok) {
            throw new Error(`Server responded with status ${response.status}`);
        }

        const data = await response.json();

        if (data.status === 'error') {
            if (data.error && data.error.includes('Rate limit exceeded')) {
                displayRateLimitInfo(data.error);
                alert(data.error);
            } else {
                 updateConversationDisplay('error', data.error || 'An unknown error occurred.');
            }
        } else if (data.conversation) {
             // Clear previous content and display full updated conversation
             conversationContainer.innerHTML = ''; // Clear before repopulating
             data.conversation.forEach(msg => {
                 if (msg.role === 'assistant') {
                     // Handle Ava's emojis
                     if (msg.model === 'Ava') {
                         const emojis = extractEmojis(msg.content);
                         if (emojis.length > 0) displayEmojis(emojis);
                         // Display non-emoji text from Ava if any
                         const nonEmojiText = msg.content.replace(/([\u2700-\u27BF]|[\uE000-\uF8FF]|[\uD83C-\uDBFF][\uDC00-\uDFFF])/g, '').trim();
                         if (nonEmojiText) {
                            updateConversationDisplay('assistant', nonEmojiText, msg.model);
                         }
                     } else {
                         updateConversationDisplay('assistant', msg.content, msg.model);
                     }
                 } else if (msg.role === 'user') {
                     updateConversationDisplay('user', msg.content);
                 }
                 // System messages are generally not displayed unless specifically needed
             });

            // Clear the prompt field after successful submission
            initialPrompt.value = '';
            promptCounter.textContent = '0/5000 characters';
        }

    } catch (error) {
         updateConversationDisplay('error', error.message);
    } finally {
        // Re-enable buttons
        document.getElementById('startConversationButton').disabled = false;
        document.getElementById('clearConversationButton').disabled = false;
        document.getElementById('startConversationButton').textContent = 'Send';
        document.getElementById('startConversationButton').classList.remove('loading');
    }
});

// Clear Conversation
    document.getElementById('clearConversationButton').addEventListener('click', async () => { // Renamed ID
    const conversationContainer = document.getElementById('conversationContainer'); // Renamed ID
    const emojiContainer = document.getElementById('emojiContainer');

    if (!confirm('Are you sure you want to clear the conversation?')) {
        return;
    }

    try {
        const token = await grecaptcha.execute('<?php echo $site_key; ?>', {action: 'clear_conversation'});

        const response = await fetch('gp_model_api_updated.php', { // Renamed API file
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'clear_conversation', // Changed action name
                recaptcha: token
            })
        });

        const data = await response.json();

        if (data.status === 'success') {
            conversationContainer.innerHTML = '';
            emojiContainer.innerHTML = '';
            // Clear input fields as well
            document.getElementById('initialPrompt').value = '';
            document.getElementById('filePath').value = '';
            document.getElementById('programInstructions').value = '';
            document.getElementById('promptCounter').textContent = '0/5000 characters';
            document.getElementById('filePathCounter').textContent = '0/255 characters';
            document.getElementById('programInstructionsCounter').textContent = '0/50000 characters';
            document.getElementById('programStatus').textContent = '';

            alert('Conversation cleared successfully.');
            document.body.style.backgroundColor = '#f5f7fa';
            displayRateLimitInfo('');
        } else {
            alert('Failed to clear the conversation: ' + (data.error || data.message));
        }
    } catch (error) { // Catch block expects an error variable
         alert('Error clearing the conversation: ' + error.message);
    }
});

// Handle program/file creation
document.getElementById('createProgramButton').addEventListener('click', async () => { // Renamed ID
    const path = document.getElementById('filePath').value.trim();
    const instructions = document.getElementById('programInstructions').value.trim();
    const showCodeInChat = document.getElementById('showCodeInChat').checked;
    const programStatus = document.getElementById('programStatus'); // Renamed ID

    const useHistoryCheckbox = document.querySelector('input[name="use_history"]');
    const use_history = useHistoryCheckbox && useHistoryCheckbox.checked;

    if (!path) {
        programStatus.textContent = 'Please enter a file path.';
        return;
    }
     if (!instructions) {
        programStatus.textContent = 'Please enter instructions or code.';
        return;
    }

    programStatus.textContent = 'Requesting code generation...';
    document.getElementById('createProgramButton').disabled = true; // Disable button

    try {
        const token = await grecaptcha.execute('<?php echo $site_key; ?>', {action: 'create_program'}); // New action name

        const response = await fetch('gp_model_api_updated.php', { // Renamed API file
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'create_program', // New action name
                filePath: path, // Changed parameter name
                instructions: instructions,
                showCodeInChat: showCodeInChat, // New parameter
                recaptcha: token,
                use_history: use_history
            })
        });

        const data = await response.json();

        if (data.success) {
            programStatus.innerHTML = `<span style="color: green;">${escapeHtml(data.message)}</span>`; // Use innerHTML for potential styling

             // Display confirmation or code in chat if requested
             if (data.confirmation_message) {
                 updateConversationDisplay('assistant', data.confirmation_message, 'Charles');
             }
             if (showCodeInChat && data.content) {
                 updateConversationDisplay('assistant', data.content, 'Charles');
             }


            // Optionally clear fields after success
            // document.getElementById('filePath').value = '';
            // document.getElementById('programInstructions').value = '';

            displayRateLimitInfo(''); // Clear any previous rate limit info
        } else {
            if (data.message && data.message.includes('Rate limit exceeded')) {
                displayRateLimitInfo(data.message);
            }
            programStatus.innerHTML = `<span style="color: red;">${escapeHtml(data.message)}</span>`;
            // Display error in chat as well for visibility
            updateConversationDisplay('error', data.message);
        }
    } catch (error) {
        programStatus.textContent = 'Error creating file: ' + error.message;
        updateConversationDisplay('error', 'Error creating file: ' + error.message);
    } finally {
         document.getElementById('createProgramButton').disabled = false; // Re-enable button
    }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // State & handles
  let heartbeatEnabled = true;
  let heartbeatIntervalId = null;
  const heartbeatIndicator = document.getElementById('heartbeatIndicator');

  if (!heartbeatIndicator) {
    console.error('❌ heartbeatIndicator element not found!');
    return;
  }

  // Send one heartbeat ping if enabled
  function sendHeartbeat() {
    if (!heartbeatEnabled) return;
    fetch('gp_model_api_updated.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'heartbeat' })
    })
    .then(res => {
      if (!res.ok) throw new Error(`Heartbeat failed: ${res.status}`);
      return res.json();
    })
    .then(data => {
      if (data.status === 'reengaged' && Array.isArray(data.conversation)) {
        refreshConversation(data.conversation);
      }
    })
    .catch(err => console.error('Heartbeat error:', err));
  }

  // Start auto-pulsing heartbeats
  function startHeartbeat() {
    sendHeartbeat();
    heartbeatIntervalId = setInterval(sendHeartbeat, 20000);
  }

  // Stop auto-pulsing
  function stopHeartbeat() {
    clearInterval(heartbeatIntervalId);
  }

  // Toggle on click
  heartbeatIndicator.addEventListener('click', () => {
    heartbeatEnabled = !heartbeatEnabled;
    heartbeatIndicator.classList.toggle('pulsing', heartbeatEnabled);
    heartbeatIndicator.classList.toggle('stopped', !heartbeatEnabled);
    console.log('❤️ Heart toggled – now', heartbeatEnabled ? 'ON' : 'OFF');
    if (heartbeatEnabled) startHeartbeat();
    else stopHeartbeat();
  });

  // Kick things off
  startHeartbeat();
});
</script>
</body>
</html>
