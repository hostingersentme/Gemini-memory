<?php
/* db_handler.php */
if (session_status() === PHP_SESSION_NONE) {
    session_start();                       // <-- ensures $_SESSION exists
}
require 'api_key.php';   // <-- load your API_KEY environment variable
// Database configuration (placeholders - user will need to fill these in)
define('DB_HOST', 'host');
define('DB_NAME', 'uname');
define('DB_USER', 'user');
define('DB_PASS', 'password');

/* ----------  Embedding & hybrid-score constants  ---------- */
/* ----------  Gemini Embedding constants  ---------- */
// Re-use the **same $geminiApiKey** you already load in gp_model_api_updated.php
define('GEMINI_EMBED_MODEL', 'gemini-embedding-exp-03-07');  // free experimental model

// If you keep the API key only in api_key.php, expose it here:
global $geminiApiKey;   // will be set in parent include

define('W_SEMANTIC', 1.0);   // weight of cosine-sim
define('W_KEYWORD', 0.5);    // weight of MySQL MATCH score
define('W_FREQ',    0.2);    // log(access_count)
define('W_RECENCY', 0.1);    // 1 / days_since_last_access

/* ----------  Tiny helper: call embedding API & normalise  ---------- */
function get_text_embedding(string $text): ?array {
    global $geminiApiKey;
    $text = trim($text);
    if ($text === '' || empty($geminiApiKey)) {
        return null;
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . GEMINI_EMBED_MODEL . ':embedContent?key=' . $geminiApiKey;
    $payload = ['content' => ['parts' => [['text' => $text]]]];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $raw      = curl_exec($ch);
    $info     = curl_getinfo($ch);
    $httpCode = $info['http_code'] ?? 0;
    $err      = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        gp_model_write_debug_log("Embedding request failed: $err");
        return null;
    }
    if ($httpCode !== 200) {
        gp_model_write_debug_log("Embedding succeeded — HTTP $httpCode, vector length=" . count($vec));
        return null;
    }

    $json = json_decode($raw, true);
    if (isset($json['error'])) {
        gp_model_write_debug_log("Embedding API error: " . ($json['error']['message'] ?? json_encode($json)));
        return null;
    }

    // Gemini’s response shape: { "embedding": { "values": [ ... ] } }
    if (!isset($json['embedding']['values']) || !is_array($json['embedding']['values'])) {
        gp_model_write_debug_log("No embedding found in JSON: " . json_encode($json));
        return null;
    }
    $vec = $json['embedding']['values'];

    // Normalize to a unit vector
    $len = sqrt(array_reduce($vec, fn($c, $v) => $c + $v * $v, 0)) ?: 1;
    return array_map(fn($v) => $v / $len, $vec);
}

function cosine_similarity(array $a, array $b): float {
    $sum = 0.0;  $n = min(count($a), count($b));
    for ($i=0;$i<$n;$i++) $sum += $a[$i]*$b[$i];
    return $sum;
}

/**
 * Establishes a PDO database connection.
 *
 * @return PDO|null A PDO connection object on success, or null on failure.
 */
function get_db_connection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log the error - using the existing gp_model_write_debug_log function if available
            // or a dedicated DB error log.
            if (function_exists('gp_model_write_debug_log')) {
                gp_model_write_debug_log("Database Connection Error: " . $e->getMessage());
            } else {
                error_log("Database Connection Error: " . $e->getMessage());
            }
            return null;
        }
    }
    return $pdo;
}

/**
 * Stores a new memory chunk into the long_term_memories table.
 *
 * @param string $memory_content The main content of the memory.
 * @param string|null $user_id Optional user identifier.
 * @param string|null $interaction_id Optional interaction identifier.
 * @param string|null $keywords_ai Comma-separated AI-generated keywords.
 * @param string|null $keywords_prompt Comma-separated user prompt keywords.
 * @param string|null $context Additional context.
 * @param string|null $source_slot The originating slot (e.g., 'Charles').
 * @param string|null $related_memory_ids Comma-separated related memory IDs.
 * @return int|false The ID of the newly inserted memory on success, or false on failure.
 */
function store_long_term_memory(
    $memory_content,
    $user_id            = null,
    $interaction_id     = null,
    $keywords_ai        = null,
    $keywords_prompt    = null,
    $context            = null,
    $source_slot        = null,
    $related_memory_ids = null
) {
    $pdo = get_db_connection();
    if (!$pdo) return false;

    /* --- generate (or skip) embedding --- */
    $embedding_json = null;
    if (!empty($memory_content)) {
        // choose ONE of the helpers you implemented
        // $vec = get_text_embedding_openai($memory_content);
        // or
        // $vec = get_text_embedding_gemini($memory_content);
        // $vec = null;                      // <-- temp: skip vector until helper ready
        /* generate the vector once, using the OpenAI helper */
        $vec = get_text_embedding($memory_content);   // returns null on failure
        if (is_array($vec)) {
        $embedding_json = json_encode($vec);
        }
 }
    

    $sql = "INSERT INTO long_term_memories (
                user_id, interaction_id, memory_content,
                keywords_ai, keywords_prompt, context,
                source_slot, related_memory_ids, access_count, embedding
            ) VALUES (
                :user_id, :interaction_id, :memory_content,
                :keywords_ai, :keywords_prompt, :context,
                :source_slot, :related_memory_ids, 0, :embedding
            )";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id',            $user_id);
        $stmt->bindParam(':interaction_id',     $interaction_id);
        $stmt->bindParam(':memory_content',     $memory_content);
        $stmt->bindParam(':keywords_ai',        $keywords_ai);
        $stmt->bindParam(':keywords_prompt',    $keywords_prompt);
        $stmt->bindParam(':context',            $context);
        $stmt->bindParam(':source_slot',        $source_slot);
        $stmt->bindParam(':related_memory_ids', $related_memory_ids);
        $stmt->bindParam(':embedding',          $embedding_json);   // ←  now bound
        $stmt->execute();
        $newId = (int)$pdo->lastInsertId();          // remember what we just wrote
        $_SESSION['ltm_saved_this_segment'][] = $newId;
        return $newId;
    } catch (PDOException $e) {
        gp_model_write_debug_log("Error storing long-term memory: " . $e->getMessage());
        return false;
    }
}

/**
 * Searches long-term memories based on keywords using positional placeholders.
 *
 * @param array $keywords An array of keywords to search for.
 * @param string|null $user_id Optional user ID to filter memories.
 * @param int $limit Maximum number of memories to return.
 * @param string|null $current_session_id Optional current session ID to filter out memories from the same session.
 * @return array An array of memory records, or an empty array if no matches or on error.
 */
function search_long_term_memories(
    array        $keywords,
    $user_id = null,
    int          $limit = 5,
    $current_session_id = null,
    ?string      $query_text = null          // NEW – full query for embedding
    ) {
    $pdo = get_db_connection();
    if (!$pdo || empty($keywords)) {
        return [];
    }

    /* ---------- 0. query embedding once ---------- */
    $query_vec = null;
    if ($query_text && trim($query_text) !== '') {
        $query_vec = get_text_embedding($query_text);
    }

    // Prepare a single search string from the keywords array
    $search_string_for_binding = implode(' ', array_map('trim', $keywords));
    $search_string_for_binding = trim($search_string_for_binding);
    if (empty($search_string_for_binding)) {
        // If keywords result in an empty search string, return no results to avoid MATCH errors
        if (function_exists('gp_model_write_debug_log')) {
            gp_model_write_debug_log("Search keywords resulted in an empty string. Skipping database search.");
        }
        return [];
    }

    $ordered_params = [];

    // Construct the SQL query with positional placeholders (?)
    $sql = "SELECT *, 
                (MATCH(keywords_ai) AGAINST(? IN BOOLEAN MODE)) * 2 +
                (MATCH(keywords_prompt) AGAINST(? IN BOOLEAN MODE)) * 1.5 +
                (MATCH(memory_content) AGAINST(? IN NATURAL LANGUAGE MODE)) * 1 +
                (access_count * 0.1) + 
                (UNIX_TIMESTAMP(COALESCE(last_accessed_at, created_at)) / 10000000) AS calculated_relevance
            FROM long_term_memories";

    // Add parameters for the SELECT part's MATCH clauses
    $ordered_params[] = $search_string_for_binding; // For MATCH(keywords_ai) in SELECT
    $ordered_params[] = $search_string_for_binding; // For MATCH(keywords_prompt) in SELECT
    $ordered_params[] = $search_string_for_binding; // For MATCH(memory_content) in SELECT

    $where_conditions = [];
    // Main search condition using MATCH AGAINST
    $where_conditions[] = "(MATCH(keywords_ai) AGAINST(? IN BOOLEAN MODE) OR 
                           MATCH(keywords_prompt) AGAINST(? IN BOOLEAN MODE) OR 
                           MATCH(memory_content) AGAINST(? IN NATURAL LANGUAGE MODE))";
    
    // Add parameters for the WHERE part's MATCH clauses
    $ordered_params[] = $search_string_for_binding; // For MATCH(keywords_ai) in WHERE
    $ordered_params[] = $search_string_for_binding; // For MATCH(keywords_prompt) in WHERE
    $ordered_params[] = $search_string_for_binding; // For MATCH(memory_content) in WHERE

    // Add user_id condition if provided
    if ($user_id !== null) {
        $where_conditions[] = "user_id = ?";
        $ordered_params[] = $user_id;
    }
    
    // Hide memories we just wrote in this chat segment
    $suppress = $_SESSION['ltm_saved_this_segment'] ?? [];
    if ($suppress) {
        // add "?, ?, …" placeholders and push IDs onto $ordered_params
        $where_conditions[] = 'id NOT IN (' .
                              implode(',', array_fill(0, count($suppress), '?')) .
                              ')';
        $ordered_params = array_merge($ordered_params, $suppress);

        if (function_exists('gp_model_write_debug_log')) {gp_model_write_debug_log("Filtering out memories from current session: " . $current_session_id);
        }
    }

    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(' AND ', $where_conditions);
    }

    $sql .= " ORDER BY calculated_relevance DESC LIMIT ?";
    $ordered_params[] = (int)$limit;

    if (function_exists("gp_model_write_debug_log")) {
        gp_model_write_debug_log("Executing SQL (positional) for search: " . $sql);
        gp_model_write_debug_log("With Positional Params: " . json_encode($ordered_params));
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ordered_params); // Execute with the ordered array of parameters
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // After fetching, update access_count and last_accessed_at for the retrieved memories
        if (!empty($results)) {
            $ids_to_update = array_column($results, 'id');
            if (!empty($ids_to_update)) {
                // This part already uses positional placeholders, so it's fine.
                $update_sql = "UPDATE long_term_memories 
                               SET access_count = access_count + 1, last_accessed_at = CURRENT_TIMESTAMP 
                               WHERE id IN (" . implode(',', array_fill(0, count($ids_to_update), '?')) . ")";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute($ids_to_update);
            }
        }
        /* ---------- 2. hybrid re-rank in PHP ---------- */
    foreach ($results as &$row) {
        $kw      = $row['calculated_relevance'] ?? 0;
        $freq    = log(1 + ($row['access_count'] ?? 0));
        $recDays = max(1, (time() - strtotime($row['last_accessed_at'] ?? $row['created_at']))/86400);
        $recency = 1 / $recDays;
        $sem     = 0.0;
        if ($query_vec && !empty($row['embedding'])) {
            $mem_vec = json_decode($row['embedding'], true);
            if (is_array($mem_vec)) $sem = cosine_similarity($query_vec, $mem_vec);
        }
        $row['hybrid_score'] = $sem*W_SEMANTIC + $kw*W_KEYWORD + $freq*W_FREQ + $recency*W_RECENCY;
        
        gp_model_write_debug_log(
        "Hybrid rank mem#{$row['id']}  sem=".round($sem,3).
        " kw=".round($kw,2)." freq=".round($freq,2).
        " recency=".round($recency,3)."  ⇒ score={$row['hybrid_score']}");
    }
    unset($row);
    usort($results, fn($a,$b)=>$b['hybrid_score'] <=> $a['hybrid_score']);
    return array_slice($results, 0, $limit);
    

 

    } catch (PDOException $e) {
        if (function_exists('gp_model_write_debug_log')) {
            gp_model_write_debug_log("Error searching long-term memories (positional): " . $e->getMessage() . " SQL: " . $sql . " Params: " . json_encode($ordered_params));
        } else {
            error_log("Error searching long-term memories (positional): " . $e->getMessage() . " SQL: " . $sql . " Params: " . json_encode($ordered_params));
        }
        return [];
    }
}

/**
 * Logs a database error message.
 * This is a placeholder and can be expanded or integrated with a more robust logging system.
 *
 * @param string $message The error message to log.
 * @param string|null $sql The SQL query that caused the error (optional).
 */
function log_db_error($message, $sql = null) {
    $log_entry = "DB Error: " . $message;
    if ($sql) {
        $log_entry .= " | SQL: " . $sql;
    }
    // Using the existing debug log function if available
    if (function_exists('gp_model_write_debug_log')) {
        gp_model_write_debug_log($log_entry);
    } else {
        // Fallback to standard PHP error log or a dedicated DB error file
        error_log($log_entry);
    }
}

?>
